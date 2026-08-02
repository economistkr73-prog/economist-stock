<?php
/* ════════════════════════════════════════════════════════════════════════
 * 종목 신호 알림 (Pushover) — 2026-08-02 신설
 *
 * 지금까지 신호는 계산만 되고 배달이 없었다 — 15:50(오늘 잠정)·08:05(SUE)에 갱신돼도
 * 사용자가 화면에 들어가야만 존재했다. 이 모듈이 크론 두 곳의 끝에 붙어 "새로 생긴 것만" 쏜다.
 *
 *   pf_alert_eod($pdo)   — 15:50 dart_eod 끝: ①관심종목 트리거 발동(돌파확인·계단지지)
 *                          ②보유종목 계단관통↓ 신규 ③오늘 신규 매집형 신호(잠정)
 *   pf_alert_fresh($pdo) — 08:05 dart_fresh 끝: ④보유종목 어닝쇼크(SUE≤−1) 신규
 *                          ⑤관심종목 새 SUE ≥ 1
 *
 * 원칙:
 *   - 조건 없으면 발송하지 않는다 (빈 알림은 무시 습관을 만든다).
 *   - 「신규」 판정 = pf_alert_log 에 INSERT IGNORE — 같은 신호를 두 번 쏘지 않는다.
 *   - 자동 매매 없음 — 알림은 「판단 소집」이다 (장기물림·계단관통 배지와 같은 철학).
 *   - 판정 함수는 화면과 같은 것을 쓴다(KrxAmt::boxStatusMany · pf_surge_badge 임계 · lib/sue.php)
 *     — 화면과 알림이 딴소리를 하면 못 믿는다.
 *   - 실패는 조용히 삼킨다 — 알림이 크론 본업(수집)을 죽이면 안 된다.
 * ════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/sue.php';

/** 발송 이력 — (kind, ref) 가 이미 있으면 그 신호는 이미 알렸다 */
function pf_alert_ensure(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pf_alert_log (
          kind    VARCHAR(10) NOT NULL,
          ref     VARCHAR(60) NOT NULL,
          sent_at DATETIME    NOT NULL,
          PRIMARY KEY (kind, ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** 신규면 true (기록까지 한다) — INSERT IGNORE 라 동시 실행에도 안전 */
function pf_alert_new(PDO $pdo, string $kind, string $ref): bool
{
    $st = $pdo->prepare("INSERT IGNORE INTO pf_alert_log (kind, ref, sent_at) VALUES (?,?,NOW())");
    $st->execute([$kind, mb_substr($ref, 0, 60)]);
    return $st->rowCount() > 0;
}

/** 배지 임계 — stock/index.php pf_surge_badge 와 같은 값 (여기선 라벨만 필요해 축약판) */
function pf_alert_badge(?float $avgMul, ?float $chg): string
{
    if ($chg !== null && $chg >= 0.20) return '불꽃형';        // 옛 추격주의
    if ($avgMul !== null && $avgMul >= 20) return '불꽃형';    // 옛 폭발형 (2026-08-02 통합)
    if ($avgMul !== null && $avgMul <= 5 && $chg !== null && $chg >= 0 && $chg < 0.10) return '매집형';
    return '중립';
}

/** 종목명 몇 개 — 알림 본문용 */
function pf_alert_names(PDO $pdo, array $codes): array
{
    if (!$codes) return [];
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $pdo->prepare("SELECT stock_code, stock_name FROM all_stock_info WHERE stock_code IN ($in)");
    $st->execute($codes);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['stock_code']] = $r['stock_name'];
    return $m;
}

/**
 * 알림 단계 자체가 죽었을 때 — CRON.md §2.5 규칙 3(자체 catch 로 삼키는 예외는 직접 priority 1)
 * + 규칙 5(스로틀). 신호 알림이 조용히 멈추면 "신호 없음"과 구별이 안 되므로 크게 알린다.
 */
function pf_alert_fail(string $which, Throwable $e): void
{
    $flag = sys_get_temp_dir() . '/pf_alert_fail_' . $which . '.flag';
    if (is_file($flag) && date('Y-m-d', (int)filemtime($flag)) === date('Y-m-d')) return;   // 하루 1회
    @touch($flag);
    if (class_exists('Notify')) {
        Notify::send('종목 신호 알림(' . $which . ') 단계가 실패했습니다 — 신호가 조용히 누락될 수 있습니다.'
            . "\n" . mb_substr($e->getMessage(), 0, 300),
            'https://economist.kr/cron_job.php?task=dart_' . $which . '&k=econ-cron-j7k2&log=1',
            ['title' => '⚠️ 신호 알림 실패', 'priority' => 1]);
    }
}

/** 모아서 한 건으로 발송 — 모닝브리핑과 같은 「헤드라인 + 링크」 패턴 */
function pf_alert_send(string $title, array $lines, string $url): bool
{
    if (!$lines || !class_exists('Notify')) return false;
    Notify::send(implode("\n", array_slice($lines, 0, 10))
        . (count($lines) > 10 ? "\n… 외 " . (count($lines) - 10) . '건' : ''),
        $url, ['title' => $title]);
    return true;
}

/**
 * 15:50 마감 알림 — 수급 신호. 오늘 잠정치(src='n') 기준이라 「잠정」을 밝힌다
 * (내일 13:05 KRX 확정값이 덮으면 판정이 뒤집힐 수 있다 — 그건 화면과 같은 한계).
 */
function pf_alert_eod(PDO $pdo): array
{
    pf_alert_ensure($pdo);
    $ka    = new KrxAmt($pdo);
    $lines = [];

    // 감시 대상: 보유(open) + 관심종목
    $held  = $pdo->query("SELECT DISTINCT stock_code FROM pf_position WHERE status = 'open'")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $watch = $pdo->query("SELECT stock_code FROM pf_watchlist")->fetchAll(PDO::FETCH_COLUMN);
    $all   = array_values(array_unique(array_merge($held, $watch)));
    $names = pf_alert_names($pdo, $all);
    $nm    = fn(string $c): string => ($names[$c] ?? $c);

    // ① / ② — 최근 신호의 박스 상태 (관심종목 관제탑·계단관통 배지와 같은 판정)
    if ($all) {
        $in = implode(',', array_fill(0, count($all), '?'));
        $sg = $pdo->prepare("
            SELECT s.code, s.d, s.avg_mul, s.chg FROM krx_surge s
              JOIN (SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code) m
                ON m.code = s.code AND m.d = s.d");
        $sg->execute($all);
        $sigRows = $sg->fetchAll(PDO::FETCH_ASSOC);
        $sigs    = array_map(fn($s) => ['code' => $s['code'], 'd' => $s['d']], $sigRows);
        $box     = $sigs ? $ka->boxStatusMany($sigs) : [];

        foreach ($sigRows as $s) {
            $b = $box[$s['code'] . '|' . $s['d']] ?? null;
            if (!$b) continue;
            $badge = pf_alert_badge(
                $s['avg_mul'] !== null ? (float)$s['avg_mul'] : null,
                $s['chg'] !== null ? (float)$s['chg'] : null);

            // ① 관심종목 트리거 — 검증된 매수규칙 둘만 (그 외는 관망이라 알리지 않는다)
            if (in_array($s['code'], $watch, true) && $badge === '매집형') {
                if ($b['st'] === 'bx-brk' && pf_alert_new($pdo, 'trig', $s['code'] . '|' . $s['d'] . '|brk')) {
                    $lines[] = '🟢 ' . $nm($s['code']) . ' 돌파확인 — 매집형×돌파 (실측 +2.26%·57.9%)';
                } elseif ($b['st'] === 'bx-lad' && mb_strpos($b['txt'], '계단지지') === 0
                          && mb_strpos($b['txt'], '⚠') === false
                          && pf_alert_new($pdo, 'trig', $s['code'] . '|' . $s['d'] . '|lad')) {
                    $lines[] = '🟢 ' . $nm($s['code']) . ' 계단지지 — 매집형×아래층3개↑ (실측 +2.40%·58.5%)';
                }
            }
            // ② 보유종목 계단관통↓ — 지지구조 소멸 (물림 클러스터 실측 신호)
            if (in_array($s['code'], $held, true)
                && $b['st'] === 'bx-dn' && str_contains((string)$b['txt'], '지지이탈')
                && pf_alert_new($pdo, 'stair', $s['code'] . '|' . $s['d'])) {
                $lines[] = '🔻 보유 ' . $nm($s['code']) . ' 계단관통↓ — 지지구조 전부 붕괴, 재평가 소집';
            }
        }
    }

    // ③ 오늘 신규 매집형 신호 (잠정 · 하한 100억 — 퀀트 목록 기본값과 동일)
    try {
        $today = date('Y-m-d');
        $sc    = $ka->surgeCached($today);
        $acc   = [];
        foreach ($sc['rows'] ?? [] as $r) {
            if ((float)$r['amt'] < 1e10) continue;
            $badge = pf_alert_badge(
                $r['avg_mul'] !== null ? (float)$r['avg_mul'] : null,
                $r['chg'] !== null ? (float)$r['chg'] : null);
            if ($badge !== '매집형') continue;
            if (pf_alert_new($pdo, 'acc', $r['code'] . '|' . ($sc['date'] ?? $today))) $acc[] = $r['code'];
        }
        if ($acc) {
            $accN = pf_alert_names($pdo, $acc);
            $show = array_slice($acc, 0, 5);
            $lines[] = '📦 오늘 매집형 신호(잠정) ' . count($acc) . '종목: '
                     . implode('·', array_map(fn($c) => $accN[$c] ?? $c, $show))
                     . (count($acc) > 5 ? ' 외' : '');
        }
    } catch (Throwable $e) { /* 신호 캐시 미구축 — ③ 없이 */ }

    pf_alert_send('📈 마감 신호', $lines, 'https://economist.kr/stock/index.php?mode=watch');
    return $lines;
}

/**
 * 08:05 아침 알림 — 실적 신호. dart_fresh(재무 5슬롯 + 접수일 원장)가 끝난 직후라
 * 최근 접수 공시의 SUE 가 계산 가능한 상태다.
 */
function pf_alert_fresh(PDO $pdo): array
{
    pf_alert_ensure($pdo);
    $lines = [];

    $held  = $pdo->query("SELECT DISTINCT stock_code FROM pf_position WHERE status = 'open'")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $watch = $pdo->query("SELECT stock_code FROM pf_watchlist")->fetchAll(PDO::FETCH_COLUMN);
    $names = pf_alert_names($pdo, array_values(array_unique(array_merge($held, $watch))));
    $nm    = fn(string $c): string => ($names[$c] ?? $c);

    // ④ 보유 어닝쇼크 (SUE ≤ −1) — 손절 플레이북의 실적 신호. 분기(qk)당 한 번만 알린다.
    foreach ($held as $c) {
        try {
            $sq = pf_sue_stock($pdo, $c);
            if (!$sq) continue;
            $qk = array_key_last($sq);
            $v  = $sq[$qk];
            if ($v <= -1 && pf_alert_new($pdo, 'shock', $c . '|' . $qk)) {
                $lines[] = '🔻 보유 ' . $nm($c) . ' 어닝쇼크 — SUE ' . number_format($v, 1)
                         . ' (쇼크 무리 = 두 달 하방 드리프트 실측)';
            }
        } catch (Throwable $e) { /* 재무 없음 — 다음 종목 */ }
    }

    // ⑤ 관심종목 새 서프라이즈 (SUE ≥ 1) — 담아 둔 후보의 실적 전제가 강해졌다는 신호
    foreach ($watch as $c) {
        try {
            $sq = pf_sue_stock($pdo, $c);
            if (!$sq) continue;
            $qk = array_key_last($sq);
            $v  = $sq[$qk];
            if ($v >= 1 && pf_alert_new($pdo, 'sue', $c . '|' . $qk)) {
                $lines[] = '⭐ 관심 ' . $nm($c) . ' 서프라이즈 — SUE ' . number_format($v, 1) . ' (상위 20%권)';
            }
        } catch (Throwable $e) { /* 다음 종목 */ }
    }

    pf_alert_send('📊 실적 신호', $lines, 'https://economist.kr/stock/index.php?mode=earn');
    return $lines;
}
?>
