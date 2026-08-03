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

/**
 * 배지 임계 — pf_surge_badge 와 <b>같은 상수</b>를 본다 (여기선 라벨만 필요해 축약판).
 * ★M5 이전에는 같은 숫자를 손으로 옮겨 적고 주석으로 「같은 값」이라 약속했다.
 *   보고서 §5.3 이 이 무리를 <b>네 벌</b>로 셌다 — 이제 정본은 classes/Thr.class 하나다.
 */
function pf_alert_badge(?float $avgMul, ?float $chg): string
{
    if ($chg !== null && $chg >= Thr::FLAME_CHG) return '불꽃형';           // 옛 추격주의
    if ($avgMul !== null && $avgMul >= Thr::FLAME_AVGMUL) return '불꽃형';  // 옛 폭발형 (2026-08-02 통합)
    if ($avgMul !== null && $avgMul <= Thr::ACC_AVGMUL_MAX
        && $chg !== null && $chg >= Thr::ACC_CHG_MIN && $chg < Thr::ACC_CHG_MAX) return '매집형';
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

    /* 감시 대상: 보유(open) + 관심종목. 이름표만 한 번에 받아 두고, 판정은 아래에서 <b>따로</b> 돈다 —
     * ①은 관심종목의 최신 신호, ②는 보유 포지션의 편입 기준 박스로 기준 시점이 다르기 때문이다(M3). */
    $held  = $pdo->query("SELECT DISTINCT stock_code FROM pf_position WHERE status = 'open'")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $watch = $pdo->query("SELECT stock_code FROM pf_watchlist")->fetchAll(PDO::FETCH_COLUMN);
    $names = pf_alert_names($pdo, array_values(array_unique(array_merge($held, $watch))));
    $nm    = fn(string $c): string => ($names[$c] ?? $c);

    /* ① 관심종목 트리거 — <b>최신 신호</b>가 맞다. 여기는 퀀트 섹션(발견·검증)이고
     *   묻는 것이 「지금 이 종목이 살 자리인가」라 기준이 오늘로 미끄러져야 한다.
     *   ★M3 로 바뀐 것은 ② 뿐이다 — 두 물음이 다르므로 기준 시점도 다르다(v0.3 §1.1 섹션 대비표).
     *
     * ★★<b>이미 보유 중인 종목도 뺴지 않는다</b>(2026-08-03 결정). 같은 종목을 다른 포트폴리오에
     *   담을 수 있게 열어 둔 이상(§2.6) 그 신호는 여전히 실행 가능하다 — 빼면 진짜 기회를 놓친다.
     *   대신 <b>「보유중」을 문구에 박아</b> 사용자가 한눈에 구별하게 한다.
     *   중복 잡음은 pf_alert_log 가 (code|d|종류)로 막아 이벤트당 한 번만 울린다. */
    if ($watch) {
        $in = implode(',', array_fill(0, count($watch), '?'));
        $sg = $pdo->prepare("
            SELECT s.code, s.d, s.avg_mul, s.chg FROM krx_surge s
              JOIN (SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code) m
                ON m.code = s.code AND m.d = s.d");
        $sg->execute($watch);
        $sigRows = $sg->fetchAll(PDO::FETCH_ASSOC);
        $sigs    = array_map(fn($s) => ['code' => $s['code'], 'd' => $s['d']], $sigRows);
        $box     = $sigs ? $ka->boxStatusMany($sigs) : [];

        foreach ($sigRows as $s) {
            $b = $box[$s['code'] . '|' . $s['d']] ?? null;
            if (!$b) continue;
            $badge = pf_alert_badge(
                $s['avg_mul'] !== null ? (float)$s['avg_mul'] : null,
                $s['chg'] !== null ? (float)$s['chg'] : null);
            if ($badge !== '매집형') continue;   // 그 외 조합은 관망이라 알리지 않는다

            // 이미 담은 종목이면 그 사실을 문구에 남긴다 — 「새 후보」와 「추가 편입 기회」는 다른 일이다
            $own = in_array($s['code'], $held, true) ? ' <보유중>' : '';

            if ($b['st'] === 'bx-brk' && pf_alert_new($pdo, 'trig', $s['code'] . '|' . $s['d'] . '|brk')) {
                $lines[] = '🟢 ' . $nm($s['code']) . $own . ' 돌파확인 — 매집형×돌파 (실측 +2.26%·57.9%)';
            } elseif ($b['st'] === 'bx-lad' && mb_strpos($b['txt'], '계단지지') === 0
                      && mb_strpos($b['txt'], '⚠') === false
                      && pf_alert_new($pdo, 'trig', $s['code'] . '|' . $s['d'] . '|lad')) {
                $lines[] = '🟢 ' . $nm($s['code']) . $own . ' 계단지지 — 매집형×아래층3개↑ (실측 +2.40%·58.5%)';
            }
        }
    }

    /* ② 보유종목 계단관통↓ — 지지구조 소멸 (물림 클러스터 실측 신호).
     * ★★M3 (v0.3 §2.2): 기준 박스를 <b>편입 시점에 못박은 `pf_position.surge_event_d`</b> 로 바꿨다.
     *   예전엔 MAX(d) 라 「나중에 뜬 남의 박스」가 무너져도 내 포지션에 경보가 왔다.
     *   surge_event_d 가 NULL 인 포지션은 판정하지 않는다 — 기준이 없으면 침묵이 정직하다.
     * ★같은 종목을 여러 포트폴리오에 담을 수 있으므로 <b>포지션 단위</b>로 돈다.
     *   중복방지 키는 (code|d) — 같은 이벤트는 한 번만 알린다(포지션마다 쏘면 같은 말이 겹친다). */
    $pos = $pdo->query("
        SELECT p.id, p.stock_code, p.surge_event_d
          FROM pf_position p
         WHERE p.status = 'open' AND p.surge_event_d IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    if ($pos) {
        $sigs = [];
        foreach ($pos as $p) $sigs[$p['stock_code'] . '|' . $p['surge_event_d']] =
            ['code' => $p['stock_code'], 'd' => $p['surge_event_d']];
        $box = $ka->boxStatusMany(array_values($sigs));
        foreach ($sigs as $key => $s) {
            $b = $box[$key] ?? null;
            if (!$b || $b['st'] !== 'bx-dn' || !str_contains((string)$b['txt'], '지지이탈')) continue;
            if (!pf_alert_new($pdo, 'stair', $key)) continue;
            $lines[] = '🔻 보유 ' . $nm($s['code']) . ' 계단관통↓ — 편입 기준 박스(' . $s['d']
                     . ')의 지지구조 전부 붕괴, 재평가 소집';
        }
    }

    // ③ 오늘 신규 매집형 신호 (잠정 · 하한은 Thr::SURGE_MIN_AMT — 퀀트 목록·accBoxes 와 같은 상수)
    try {
        $today = date('Y-m-d');
        $sc    = $ka->surgeCached($today);
        $acc   = [];
        foreach ($sc['rows'] ?? [] as $r) {
            if ((float)$r['amt'] < Thr::SURGE_MIN_AMT) continue;
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
            if ($v <= Thr::SUE_SHOCK && pf_alert_new($pdo, 'shock', $c . '|' . $qk)) {
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
            if ($v >= Thr::SUE_HIT && pf_alert_new($pdo, 'sue', $c . '|' . $qk)) {
                $lines[] = '⭐ 관심 ' . $nm($c) . ' 서프라이즈 — SUE ' . number_format($v, 1) . ' (상위 20%권)';
            }
        } catch (Throwable $e) { /* 다음 종목 */ }
    }

    pf_alert_send('📊 실적 신호', $lines, 'https://economist.kr/stock/index.php?mode=earn');
    return $lines;
}
?>
