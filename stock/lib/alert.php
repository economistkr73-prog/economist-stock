<?php
/* ════════════════════════════════════════════════════════════════════════
 * 종목 신호 알림 (Pushover) — 2026-08-02 신설
 *
 * 지금까지 신호는 계산만 되고 배달이 없었다 — 15:50(오늘 잠정)·08:05(SUE)에 갱신돼도
 * 사용자가 화면에 들어가야만 존재했다. 이 모듈이 크론 두 곳의 끝에 붙어 "새로 생긴 것만" 쏜다.
 *
 *   pf_alert_eod($pdo)   — 15:50 dart_eod 끝: ①관심종목 트리거 발동(돌파확인·계단지지)
 *                          ②보유종목 계단관통↓ 신규 ③오늘 신규 매집형 신호(잠정)
 *   pf_alert_fresh($pdo) — 08:05 dart_fresh 끝: ④보유·관심종목 어닝쇼크(SUE≤−1) 신규
 *                          ⑤관심종목 새 SUE ≥ 1  ⑥전종목 규칙 충족 신규(발굴)
 *   pf_alert_move($pdo)  — 15:50 dart_eod 끝(pf_alert_eod 다음): ⑦관심·단타·보유 종목의 오늘 등락률이
 *                          Thr::EOD_MOVE_PCT 를 넘은 것을 «한 건으로 요약» (신규 판정이 아니라 하루 요약 · 2026-08-31)
 *
 * ★④⑤ 와 ⑥ 은 <b>묻는 것이 다르다</b>(2026-08-05) — ④⑤ 는 「내가 보고 있는 종목의 실적이
 *   새로 튀었나」이고, ⑥ 은 「내가 아직 모르는 종목이 규칙을 충족했나」다. 그래서 ⑥ 은
 *   보유·관심을 <b>제외</b>하고 돈다(겹치면 같은 종목이 아침에 두 줄로 온다).
 *
 * ★★실적(④⑤)의 「신규」는 <b>공시 접수일</b>로 잰다 — 분기 키만으로는 어제 공시와 석 달 묵은
 *   공시가 구별되지 않는다. 문지기는 pf_alert_sue_fresh() 하나뿐이니 조건을 여기저기 다시 적지 않는다.
 *   (2026-08-04 사고: 접수일 조건이 없어, 관심종목에 새로 담기만 해도 과거 실적이 다음 날 울렸다.)
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
    /* 마감 변동 «기록» (2026-09-04) — 알림이 쏜 그 목록을 날짜별로 남긴다. 화면(mode=move)은 읽기만 한다.
     *   왜 저장하나 — 판정 재료(all_stock_info 오늘 등락률 · 보유/단타/관심 집합)가 다음 날이면 바뀐다.
     *   알림이 말한 종목과 화면이 보여 주는 종목이 같으려면 «쏜 순간»의 목록을 남겨야 한다.
     *   뉴스·차트는 저장하지 않는다 — 화면이 그때그때 받는다(비용 0 · 지난 날짜를 열면 «지금» 기준이다).
     *   watch_n(감시 종목 수)은 머리말 「감시 N종목 중」을 위해 행마다 같은 값을 둔다. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pf_move_hit (
          d        DATE          NOT NULL,
          code     CHAR(6)       NOT NULL,
          name     VARCHAR(60)   NOT NULL DEFAULT '',
          rate     DECIMAL(7,2)  NOT NULL COMMENT '등락률 %',
          price    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '종가',
          amt_eok  DECIMAL(12,1) NULL COMMENT '거래대금(억원) all_stock_info.stock_vol_cap',
          tags     VARCHAR(20)   NOT NULL DEFAULT '' COMMENT '보유·단타·관심',
          watch_n  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '그 날 감시 종목 수',
          made_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (d, code)
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

/**
 * 실적 알림의 <b>신선도 문지기</b> (2026-08-04 신설) — 「담은 뒤에 나온, 최근 공시」의 분기만 돌려준다.
 *
 * ★왜 생겼나: 옛 판정은 「최신 분기 SUE 가 문턱을 넘었나 + 아직 안 쐈나」뿐이라
 *   <b>공시 접수일이 조건에 없었다</b>. 중복방지 키가 (종목|분기)라, 종목을 관심목록에 새로 담으면
 *   그 종목의 과거 실적이 전부 「아직 안 쏜 것」이 되어 다음 아침 한꺼번에 울렸다.
 *   실측 2026-08-04: 8월 3일에 담은 세 종목이 8월 4일 08:06 에 울렸는데 공시는 5/12·5/15·7/31 —
 *   셋 다 담을 때 재무분석 화면에서 이미 본 값이었다.
 *
 * 통과 조건 둘 — <b>둘 다</b> 만족해야 알린다.
 *   ① <b>담은 뒤에 나온 공시</b>여야 한다. 담기 전 것은 담을 때 화면에서 이미 본 정보다.
 *   ② 접수일이 Thr::SUE_ALERT_FRESH_DAYS 이내여야 한다 (창 밖은 pf_sue_receipts 가 아예 안 준다).
 *
 * ★기준 시각($since)이 없으면 ②만 본다 — ①을 <b>못 재는 것</b>이지 「오래됐다」는 뜻이 아니라서,
 *   침묵보다 최근성 검사만이라도 거는 편이 정직하다(기준 없으면 침묵하는 ②계단관통과 다른 점 —
 *   거기선 기준 박스가 판정 <b>전부</b>였지만 여기선 남은 검사가 여전히 의미를 갖는다).
 *
 * @param ?string $since 이 종목을 처음 담은 시각 (관심=added_at · 보유=wl_added_at 또는 started_at)
 * @return array [qk => ['d'=>접수일, 'sue'=>float]] — 문턱 판정은 부르는 쪽 몫(④는 쇼크·⑤는 서프라이즈)
 */
function pf_alert_sue_fresh(PDO $pdo, string $code, ?string $since): array
{
    $recv = pf_sue_receipts($pdo, $code, Thr::SUE_ALERT_FRESH_DAYS);   // ② 창 밖은 여기서 걸린다
    if (!$recv) return [];
    $sq = pf_sue_stock($pdo, $code);
    if (!$sq) return [];

    $out = [];
    foreach ($recv as $qk => $dt) {
        if ($since !== null && $dt < substr($since, 0, 10)) continue;  // ① 담기 전 공시
        if (!isset($sq[$qk])) continue;                                // 이력 부족 등으로 SUE 없음
        $out[$qk] = ['d' => $dt, 'sue' => (float)$sq[$qk]];
    }
    return $out;
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

    /* 종목만이 아니라 <b>기준 시각</b>도 같이 읽는다 — 「내가 담은 뒤에 나온 공시인가」를 묻기
     * 위해서다(pf_alert_sue_fresh 참조). 보유는 관심에서 승계한 wl_added_at 이 있으면 그것을 쓴다:
     * 편입일(started_at)보다 이르고, 실제로 <b>내가 이 종목을 처음 본 날</b>이라 기준으로 정확하다.
     * 같은 종목이 여러 포트폴리오에 있으면 가장 이른 접촉을 쓴다(늦은 쪽을 쓰면 그 사이 공시를 놓친다). */
    $heldAt = [];
    foreach ($pdo->query("
        SELECT stock_code, MIN(COALESCE(wl_added_at, started_at)) t
          FROM pf_position WHERE status = 'open' GROUP BY stock_code") as $r)
        $heldAt[$r['stock_code']] = $r['t'];

    $watchAt = [];
    foreach ($pdo->query("SELECT stock_code, added_at FROM pf_watchlist") as $r)
        $watchAt[$r['stock_code']] = $r['added_at'];

    $held  = array_keys($heldAt);
    $watch = array_keys($watchAt);
    $names = pf_alert_names($pdo, array_values(array_unique(array_merge($held, $watch))));
    $nm    = fn(string $c): string => ($names[$c] ?? $c);

    /* ④ 어닝쇼크 (SUE ≤ −1) 신규 — <b>보유 + 관심종목</b>. 분기(qk)당 한 번만 알린다.
     * ★2026-08-04: 「최신 분기」가 아니라 <b>최근 접수된 공시의 분기</b>를 돈다. 옛 판정은
     *   새로 편입한 보유종목의 몇 달 전 쇼크를 편입 다음 날 아침에 울렸다(⑤와 같은 결함).
     * ★2026-08-05 <b>관심종목까지 넓혔다</b>(사용자 지시). 담아 둔 후보의 실적 전제가 무너진 것은
     *   「뺄까」를 판단할 자리인데, 전에는 보유만 울려 관심은 조용히 지나갔다.
     * ★쇼크의 조건은 <b>SUE ≤ −1 하나뿐</b>이다 — 서프라이즈 쪽의 품질·매출동반·거래대금을
     *   여기에 얹지 않는다. 그쪽은 「사는 규칙」이라 좁힐수록 정확해지지만 쇼크는 「피하는 목록」이라
     *   좁히면 피해야 할 것을 놓친다(백테스트도 하위 20%를 단일 기준으로 재서 얻은 결과다).
     *   어닝 화면 하단 「어닝 쇼크」 목록과 같은 기준이다. */
    $shockAt = $heldAt;
    foreach ($watchAt as $c => $t) {
        // 보유·관심 둘 다면 <b>이른 쪽</b>을 기준으로 — 늦은 쪽을 쓰면 그 사이 공시를 놓친다(위 heldAt 과 같은 규칙)
        $shockAt[$c] = isset($shockAt[$c]) ? min($shockAt[$c], $t) : $t;
    }
    foreach ($shockAt as $c => $since) {
        try {
            foreach (pf_alert_sue_fresh($pdo, $c, $since) as $qk => $f) {
                if ($f['sue'] > Thr::SUE_SHOCK) continue;
                // 중복방지 키는 (shock|종목|분기) 하나 — 관심에서 보유로 옮겨도 같은 쇼크를 다시 쏘지 않는다
                if (!pf_alert_new($pdo, 'shock', $c . '|' . $qk)) continue;
                $lines[] = '🔻 ' . (isset($heldAt[$c]) ? '보유 ' : '관심 ') . $nm($c)
                         . ' 어닝쇼크 — SUE ' . number_format($f['sue'], 1)
                         . ' (' . substr($f['d'], 5) . ' 공시 · 쇼크 무리 = 두 달 하방 드리프트 실측)';
            }
        } catch (Throwable $e) { /* 재무 없음 — 다음 종목 */ }
    }

    /* ⑤ 관심종목 새 서프라이즈 (SUE ≥ 1) — 담아 둔 후보의 실적 전제가 <b>새로</b> 강해졌다는 신호.
     * ★「새로」가 핵심이다. 담을 때 이미 보고 담은 값을 다시 알리면 사용자는 알림을 못 믿게 된다. */
    foreach ($watch as $c) {
        try {
            foreach (pf_alert_sue_fresh($pdo, $c, $watchAt[$c] ?? null) as $qk => $f) {
                if ($f['sue'] < Thr::SUE_HIT) continue;
                if (!pf_alert_new($pdo, 'sue', $c . '|' . $qk)) continue;
                $lines[] = '⭐ 관심 ' . $nm($c) . ' 서프라이즈 — SUE ' . number_format($f['sue'], 1)
                         . ' (' . substr($f['d'], 5) . ' 공시 · 상위 20%권)';
            }
        } catch (Throwable $e) { /* 다음 종목 */ }
    }

    /* ⑥ 전종목 규칙 충족 신규 (2026-08-05 신설) — ④⑤ 와 달리 <b>내가 아직 모르는 종목</b>을 알린다.
     *
     * ★왜 생겼나: ④⑤ 는 보유·관심에만 붙어 있어, 아무 관계 없는 종목이 백테스트 규칙을 충족해도
     *   화면에 들어가야만 존재했다. 발굴이 이 화면의 본업인데 그 자리가 비어 있었다.
     *
     * ★조건은 어닝 화면의 「규칙 충족」과 <b>같은 함수</b>에서 온다(pf_earn_rows) —
     *   SUE ≥ 1 ∧ 품질 ∧ 매출동반 ∧ 거래대금 10억↑ <b>∧ 고변동 아님</b>.
     *   마지막 항이 목록 안의 <b>상대</b> 상위 ⅓ 이라 조건을 여기 다시 적으면 재현이 안 되고,
     *   「알림은 규칙 충족이라는데 화면에선 고변동⚠」로 두 곳이 딴소리를 하게 된다.
     *
     * ★보유·관심은 뺀다 — 그쪽은 ④⑤ 가 「담은 뒤에 나온 공시」라는 더 엄한 문지기로 이미 본다.
     *   여기서 또 쏘면 같은 종목이 아침에 두 줄로 온다.
     *
     * ★첫 실행은 <b>기록만 하고 쏘지 않는다</b>. 창(30일) 안의 충족 종목이 전부 「아직 안 쏜 것」이라
     *   한꺼번에 울린다 — 2026-08-04 의 그 사고와 같은 모양이라 같은 실수를 되풀이하지 않는다. */
    try {
        $E    = pf_earn_rows($pdo, Thr::SUE_ALERT_FRESH_DAYS);
        $seen = array_flip(array_merge($held, $watch));
        $hit  = array_values(array_filter($E['rows'],
            fn($r) => $r['ok'] && !$r['high_vol'] && !isset($seen[$r['code']])));

        $seed = !$pdo->query("SELECT 1 FROM pf_alert_log WHERE kind = 'earn' LIMIT 1")->fetchColumn();
        /* ★첫 실행 표시는 <b>충족 0건이어도</b> 남긴다. 「earn 행이 하나라도 있나」로만 재면
         *   비시즌(충족 0건)에 깔았을 때 로그가 계속 비어 seed 상태에 머물고, 그러다
         *   <b>첫 진짜 배치(시즌 마감일 다음 아침 수십~수백 건)를 통째로 삼킨다</b>.
         *   깔린 날을 못 박아 두면 그 뒤로는 언제나 정상 발송이다. */
        if ($seed) pf_alert_new($pdo, 'earn', '__installed__');
        $new  = [];
        foreach ($hit as $r) {
            if (!pf_alert_new($pdo, 'earn', $r['code'] . '|' . $r['qk'])) continue;
            $new[] = $r;
        }
        /* 시즌(5·8·11월 마감일)엔 하루 수십 건이 한꺼번에 충족된다 — 다 적으면 알림이 목록이 되어
         * 아무도 안 읽는다. 상위 몇 건만 적고 나머지는 수만 밝힌 뒤 화면으로 넘긴다(rows 는 SUE 큰 순). */
        if (!$seed && $new) {
            $cap = 8;
            foreach (array_slice($new, 0, $cap) as $r) {
                $lines[] = '🟢 ' . $r['name'] . ' 규칙 충족 — SUE ' . number_format($r['sue'], 1)
                         . ' (' . substr($r['dt'], 5) . ' 공시 · 8년 중 7년 양수)';
            }
            if (count($new) > $cap) $lines[] = '… 외 ' . (count($new) - $cap) . '건 (화면에서 전부 보기)';
        }
    } catch (Throwable $e) { /* 어닝 목록 실패 — ④⑤ 는 그대로 나간다 */ }

    pf_alert_send('📊 실적 신호', $lines, 'https://economist.kr/stock/index.php?mode=earn');
    return $lines;
}

/**
 * 15:50 마감 «변동 요약» (2026-08-31 신설 · 사용자 요청) — 내가 보는 종목(관심 · 단타 풀 · 보유) 중
 * 오늘 |등락률| 이 Thr::EOD_MOVE_PCT 이상인 것을 <b>한 건</b>으로 묶어 보낸다.
 *
 * ★pf_alert_eod 와 <b>묻는 것이 다르다</b> — 저것은 「신호가 새로 생겼나」(이벤트 · 신규만), 이것은
 *   「오늘 내 종목 중 크게 움직인 게 있나」(하루 요약). 그래서 제목을 갈라 둔다(앱 목록에서 구별).
 * ★새로 받지 않는다 — 시세는 방금 job_quotes 가 메운 all_stock_info 그대로다(주식시세가져오기.md §4:
 *   있는 것을 먼저 쓴다). <b>오늘 갱신된 행(DATE(uDate)=오늘)만</b> 본다 — 낡은 시세로 「오늘 움직였다」고
 *   말하면 거짓이고, 휴장일엔 오늘 행이 없어 자연히 0건(=무음)이 된다.
 * ★세 집합의 판정을 다시 적지 않는다 — 보유 Dt::heldCodes() · 단타 Dt::activeCodes() · 관심 pf_watchlist
 *   (단타 화면의 목록·배지와 같은 원천이라 화면과 알림이 같은 종목을 「보유」라 부른다).
 * ★임계는 Thr::EOD_MOVE_PCT 하나(CLAUDE.md 규칙 3) · 중복방지 키 (code|날짜) — 같은 날 eod 를 다시
 *   돌려도 두 번 안 온다 · 0건이면 안 보낸다(CRON.md §2.5 규칙 1) · 미리보기는 $dry(안 보내고 본문만).
 *
 * @return string[] 발송한(또는 $dry 면 발송했을) 본문 줄 — 비면 무음
 */
/**
 * 마감 변동 «판정» 단일본 — 관심·단타·보유 종목 중 오늘 |등락률| ≥ Thr::EOD_MOVE_PCT 인 것.
 * 중복방지·기록·발송은 하지 않는다(pf_alert_move 가 한다) — 그래서 백필·미리보기가 같은 판정을 쓸 수 있다.
 * @return ['n'=>감시 종목 수, 'rows'=>[['code','name','rate','price','amt_eok','tags'] …] 상승 큰 것 → 하락 큰 것 순]
 */
function pf_move_scan(PDO $pdo): array
{
    $dt    = new Dt($pdo);
    $held  = array_flip($dt->heldCodes());
    $pool  = array_flip($dt->activeCodes());
    $watch = array_flip($pdo->query("SELECT stock_code FROM pf_watchlist")->fetchAll(PDO::FETCH_COLUMN));
    $codes = array_keys($held + $pool + $watch);
    if (!$codes) return ['n' => 0, 'rows' => []];

    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $pdo->prepare("
        SELECT stock_code, stock_name, stock_price, stock_rate, stock_vol_cap
          FROM all_stock_info
         WHERE stock_code IN ($in) AND DATE(uDate) = CURDATE()");
    $st->execute($codes);
    $thr = Thr::EOD_MOVE_PCT * 100;          // stock_rate 는 % 단위(3.25 = +3.25%)
    $tag = function (string $c) use ($held, $pool, $watch): string {
        $t = [];
        if (isset($held[$c]))  $t[] = '보유';
        if (isset($pool[$c]))  $t[] = '단타';
        if (isset($watch[$c])) $t[] = '관심';
        return implode('·', $t);
    };
    $up = $dn = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rate = (float)$r['stock_rate'];
        if (abs($rate) < $thr) continue;
        $row = [
            'code'    => (string)$r['stock_code'],
            'name'    => (string)$r['stock_name'],
            'rate'    => $rate,
            'price'   => (float)$r['stock_price'],
            'amt_eok' => (float)$r['stock_vol_cap'],
            'tags'    => $tag((string)$r['stock_code']),
        ];
        if ($rate >= 0) $up[] = $row; else $dn[] = $row;
    }
    usort($up, fn($a, $b) => $b['rate'] <=> $a['rate']);   // 상승 큰 것부터
    usort($dn, fn($a, $b) => $a['rate'] <=> $b['rate']);   // 하락 큰 것부터
    return ['n' => count($codes), 'rows' => array_merge($up, $dn)];
}

/** 기록 — INSERT IGNORE 라 같은 (날짜, 종목)을 두 번 넣어도 안전(백필·재실행) */
function pf_move_hits_save(PDO $pdo, string $d, int $watchN, array $rows): int
{
    if (!$rows) return 0;
    $st = $pdo->prepare("INSERT IGNORE INTO pf_move_hit (d, code, name, rate, price, amt_eok, tags, watch_n)
                         VALUES (?,?,?,?,?,?,?,?)");
    $n = 0;
    foreach ($rows as $r) {
        $st->execute([$d, $r['code'], mb_substr($r['name'], 0, 60), round($r['rate'], 2), (int)round($r['price']),
                      $r['amt_eok'] !== null ? round($r['amt_eok'], 1) : null, $r['tags'], $watchN]);
        $n += $st->rowCount() > 0 ? 1 : 0;
    }
    return $n;
}

/**
 * 화면(mode=move)용 읽기 — 날짜를 안 주면 «마지막 발생일». 표가 없으면(첫 알림 전) 빈 것으로 본다(DDL 은 크론만).
 * @return ['date'=>?string, 'n'=>감시 수, 'rows'=>[], 'prev'=>?string, 'next'=>?string, 'last'=>?string]
 */
function pf_move_hits(PDO $pdo, ?string $d = null): array
{
    $out = ['date' => $d, 'n' => 0, 'rows' => [], 'prev' => null, 'next' => null, 'last' => null];
    try {
        $out['last'] = $pdo->query("SELECT MAX(d) FROM pf_move_hit")->fetchColumn() ?: null;
        if ($d === null) $d = $out['date'] = $out['last'];
        if ($d === null) return $out;
        $st = $pdo->prepare("SELECT code, name, rate, price, amt_eok, tags, watch_n FROM pf_move_hit
                              WHERE d = ? ORDER BY (rate < 0), ABS(rate) DESC");   // ▲ 큰 것부터 → ▼ 큰 것부터
        $st->execute([$d]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['n'] = max($out['n'], (int)$r['watch_n']);
            $r['rate'] = (float)$r['rate']; $r['price'] = (int)$r['price'];
            $r['amt_eok'] = $r['amt_eok'] !== null ? (float)$r['amt_eok'] : null;
            $out['rows'][] = $r;
        }
        $st = $pdo->prepare("SELECT MAX(d) FROM pf_move_hit WHERE d < ?"); $st->execute([$d]);
        $out['prev'] = $st->fetchColumn() ?: null;
        $st = $pdo->prepare("SELECT MIN(d) FROM pf_move_hit WHERE d > ?"); $st->execute([$d]);
        $out['next'] = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { /* 표 없음 = 아직 기록 없음 */ }
    return $out;
}

function pf_alert_move(PDO $pdo, bool $dry = false): array
{
    pf_alert_ensure($pdo);
    $scan  = pf_move_scan($pdo);
    $today = date('Y-m-d');
    $rows  = [];
    foreach ($scan['rows'] as $r) {
        if (!$dry && !pf_alert_new($pdo, 'move', $r['code'] . '|' . $today)) continue;
        $rows[] = $r;
    }
    if (!$rows) return [];
    $up = array_values(array_filter($rows, fn($r) => $r['rate'] >= 0));
    $dn = array_values(array_filter($rows, fn($r) => $r['rate'] <  0));
    if (!$dry) pf_move_hits_save($pdo, $today, $scan['n'], $rows);   // 기록 — 화면(mode=move)이 읽는 그 목록

    $fmt = fn(array $r, string $arrow): string => sprintf('%s %s %+.1f%% %s · %s · %s억',
        $arrow, $r['name'], $r['rate'], number_format($r['price']), $r['tags'], number_format($r['amt_eok']));
    $lines = array_merge(array_map(fn($r) => $fmt($r, '▲'), $up), array_map(fn($r) => $fmt($r, '▼'), $dn));

    /* ★자르기를 «여기서» 한다(bx_alert 와 같은 이유) — pf_alert_send 가 10줄에서 자르므로 머리말을
     *   포함해 10줄 안에 맞춘다. 머리말이 「감시 N종목 중 M건」이라 잘린 뒤에도 전체 규모는 남는다. */
    $n    = count($lines);
    $MAX  = 8;
    $body = ['감시 ' . $scan['n'] . '종목 중 ±' . Thr::pct(Thr::EOD_MOVE_PCT) . '%↑ ' . $n
           . '건 (▲' . count($up) . ' ▼' . count($dn) . ')'];
    foreach (array_slice($lines, 0, $MAX) as $l) $body[] = $l;
    if ($n > $MAX) $body[] = '… 외 ' . ($n - $MAX) . '건 (리포트에서 전부 보기)';

    // 링크는 리포트 화면(종목 목록 + 뉴스 + 미니 일봉 · 2026-09-04) — 거기서 종목을 누르면 단타로 간다
    if (!$dry) pf_alert_send('📊 마감 변동 ' . $n . '건', $body, 'https://economist.kr/stock/index.php?mode=move&d=' . $today);
    return $body;
}
?>
