<?php
/**
 * cron/qm_collect.php — 급등주 분봉 아카이브 & 퀀트 분석
 *
 * ══ 무엇을 하나 ═══════════════════════════════════════════════════════════
 * 최근 두 달 「하루 10% 이상 오른 종목」을 `krx_amt` 에서 뽑아, 그 종목의 <b>급등일 전후
 * 10거래일(D-4~D+5) 1분봉</b>을 키움에서 천천히 받아 <b>지워지지 않는 아카이브</b>(`qm_bar`)에
 * 쌓고, 이벤트당 한 행짜리 피처 표(`qm_feat`)를 만들어 패턴을 분석한다.
 *
 * ══ ★`dt_*` 와 섞지 않는다 ════════════════════════════════════════════════
 * 단타(`dt_min`)는 <b>최근 10거래일만</b> 보관하고 매일 지운다. 아카이브를 그 표에 넣으면
 * prune 이 집어삼킨다. `qm_*` 는 `dt_*` 와 <b>조인하지 않는다</b> — 종목코드 문자열만 공유한다.
 *
 * ══ 소스는 키움 하나뿐 ════════════════════════════════════════════════════
 * `dt_min` 은 네이버를 폴백으로 쓰지만 <b>아카이브는 쓰지 않는다</b>. 이유 셋:
 *   ① 네이버는 «가장 최근 거래일 하루치»만 준다 — 두 달 전 구간엔 무용하다
 *   ② 폴백이 성공하면 `bars > 0` 이 되어 <b>목표 날짜가 안 채워졌는데도 성공으로 보인다</b>
 *   ③ 한 종목 안에 소스가 섞이면 거래량 기준(KRX 단독 vs 통합)이 흔들린다
 * 키움이 실패하면 그 (종목, 날짜)는 <b>실패로 기록하고 tries+1</b> 한다. 대체하지 않는다.
 *
 * job
 *   probe    ★착수 전 조사 — 스키마·용량·표본 크기·키움 기준일 인자 (쓰기 없음)
 *   schema   표 4개 생성 (멱등)
 *   events   급등 이벤트 추출 → qm_event / qm_task  (API 콜 0)
 *   work     수집 워커 (bg · 차수 예산 600초)
 *   verify   수집 검증
 *   feat     피처 계산 (DB 만 읽는다 · 멱등)
 *   status   현황
 *
 * SSH 실행 예
 *   php cron/qm_collect.php job=probe
 *   php cron/qm_collect.php job=events
 *   php cron/qm_collect.php job=work fg=1 n=1        ← 디버깅용 1종목 포그라운드
 */
require_once __DIR__ . '/_boot.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cronbg.inc';

$CLI = (PHP_SAPI === 'cli');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-qm';
if (!$CLI && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}
if ($CLI) {
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $_GET[$k] = $v; }
    }
}
@set_time_limit(0);
@ini_set('memory_limit', '512M');

define('QM_LOG', sys_get_temp_dir() . '/qm_collect.log');
if (!empty($_GET['log'])) { cron_bg_show_log(QM_LOG, 120); exit; }

// ══ 상수 ════════════════════════════════════════════════════════════════
/** 이벤트 조건 — 전 거래일 종가 대비 등락률 하한(%) */
const QM_CHG_MIN   = 10.0;
/** 이벤트 조건 — 거래대금 하한(원). ★krx_amt.amt 는 «원» 단위다. 100억 = 1e10 */
const QM_AMT_MIN   = 10000000000;
/** 수집 구간 — 급등일 기준 앞뒤 거래일 수 */
const QM_PRE_DAYS  = 4;
const QM_POST_DAYS = 5;
/** 이벤트일 앞에 이만큼 거래일이 없으면 신규 상장으로 보고 배제한다 */
const QM_MIN_HIST  = 60;
/** 상장주식수가 전일 대비 이만큼 넘게 변하면 분할·병합으로 보고 배제한다 */
const QM_SPLIT_TOL = 0.20;
/** 종목당(구간당) 연속조회 상한 */
const QM_MAX_CALLS = 35;
/**
 * ★한 번에 받는 구간의 최대 «거래일» 폭.
 *
 * 한 콜 ≈ 900봉 ≈ 2.5거래일이라 MAX_CALLS=35 는 약 **86거래일**이 한계다.
 * 종목의 이벤트가 그보다 넓게 흩어져 있으면(1년 백필에서 흔하다) 구간 전체를 한 번에 받으려다
 * 늘 최근 86일치만 얻고 나머지는 「봉 0」 → 재시도 → 또 같은 자리 …로 <b>영원히 수렴하지 않는다</b>.
 * 그래서 구간을 잘라 여러 차례에 나눠 받는다. 60 은 86 에 여유를 둔 값이다.
 *
 * ★자를 때 <b>이벤트 창(D-4~D+5)을 가로지르지 않는다</b> — 한 이벤트의 열흘이 두 조각으로 갈리면
 *   조각마다 받은 시점이 달라 수정주가 기준이 섞일 수 있다.
 */
const QM_CHUNK_DAYS = 60;
/**
 * ★콜 사이 간격(초). 사양서 §6 은 1초지만 **2초로 늦췄다**(2026-08-05 사용자 지시).
 *
 * 이 잡만 한 번에 수천 콜을 쏟아붓기 때문에 키움이 한도를 걸 여지를 없앤다.
 * 하루 안에만 끝나면 되는 일이라 속도를 살 이유가 없다.
 * ★`Kiwoom::GAP_USEC` 상수를 만지지 않고 `setGap()` 으로 «이 인스턴스만» 늦춘다 —
 *   상수를 늘리면 매일 20종목만 받는 `dt_min` 까지 함께 느려진다.
 */
const QM_GAP_SEC   = 2.0;
/** 차수 시간 예산(초). 크론 예산 900초 안에서 여유를 남긴다 */
const QM_ROUND_SEC = 600;
/** state=1 이 이만큼 오래 묶여 있으면 죽은 것으로 보고 되돌린다(초) */
const QM_STALE_SEC = 1800;
/**
 * ★용량 가드 — `qm_bar` 가 이 MB 를 넘으면 수집을 멈춘다.
 *
 * 2026-08-05 실측: 과제당 365봉 · 행당 70바이트. 40거래일이면 178MB,
 * <b>250거래일(1년 백필)이면 과제 57,110개 · 2,086만봉 · 약 1,391MB</b>.
 * 여기에 15% 여유를 얹어 1600 으로 둔다(DB 한도 4GB · 적재 후 전체 약 2.6GB).
 *
 * 조용히 호스팅 용량을 채우면 <b>같은 스키마를 쓰는 다른 126개 표가 함께 죽는다</b> —
 * 넘으면 멈추고 status 에 경고를 남긴다. 기간을 더 늘릴 때 이 값을 «먼저» 다시 계산한다.
 */
const QM_MAX_MB    = 1600;

$job = (string)($_GET['job'] ?? 'status');

/* `cron_bg_begin()` 은 스스로 `&bg=1` 을 보고 판단한다 — 없으면 그 자리에서 돈다.
 * 그래서 조건 없이 부르고, 오래 걸리는 호출(work·probe)에만 URL 에 &bg=1 을 붙인다. */
cron_bg_begin(QM_LOG, max(0, (int)($_GET['sec'] ?? 900)));

function say(string $s = ''): void { echo $s . "\n"; @flush(); }
function hr(string $t = ''): void { say(''); say('── ' . $t . ' ' . str_repeat('─', max(0, 60 - mb_strlen($t)))); }

$t0 = microtime(true);

// ══════════════════════════════════════════════════════════════════════════
//  공용 — 거래일
// ══════════════════════════════════════════════════════════════════════════
/**
 * `krx_amt` 가 거래일의 유일한 판정자다 — 휴장일 표를 새로 만들지 않는다
 * (퀀트·포트폴리오·단타와 같은 규칙).
 */
function qm_trading_days(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT d FROM krx_amt WHERE d BETWEEN ? AND ? AND amt > 0
                          GROUP BY d ORDER BY d");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** 그 표에 그 컬럼이 있나 (스키마를 추측하지 않는다) */
function qm_has_col(PDO $pdo, string $table, string $col): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** 종목명 — all_stock_info 는 utf8mb3 라 <b>SQL 조인하지 않고</b> 따로 읽어 붙인다 */
function qm_names(PDO $pdo, array $codes): array
{
    if (!$codes) return [];
    $out = [];
    foreach (array_chunk(array_values(array_unique($codes)), 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT stock_code, stock_name FROM all_stock_info WHERE stock_code IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['stock_code']] = $r['stock_name'];
    }
    return $out;
}

// ══ 통계 공용 (analyze · danalyze 가 같은 것을 본다) ═══════════════════════
/** 기초통계 — 없는 값(NULL)은 세지 않는다 */
function qm_stat(array $v): array
{
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    $n = count($v);
    if (!$n) return ['n' => 0];
    sort($v);
    $mean = array_sum($v) / $n;
    $var  = $n > 1 ? array_sum(array_map(fn($x) => ($x - $mean) ** 2, $v)) / ($n - 1) : 0.0;
    $q = fn($p) => $v[(int)floor(($n - 1) * $p)];
    return ['n' => $n, 'mean' => $mean, 'sd' => sqrt($var), 'se' => sqrt($var / $n),
            'med' => $q(.5), 'p25' => $q(.25), 'p75' => $q(.75),
            'win' => count(array_filter($v, fn($x) => $x > 0)) / $n * 100];
}

/**
 * ★★krx_amt 의 하루가 «어떤 날»인가 — 사후 판정에 쓸 수 있는 날인지 가른다. <b>단일본</b>.
 *
 * PHP 는 `(float)null`·`(float)'0'` 을 0 으로 읽으므로 o/h/l 을 그냥 쓰면 저가가 «−100%»,
 * 고가가 «0» 으로 계산되어 <b>가짜 손절·가짜 폭락</b>이 만들어진다. 그래서 부르는 쪽은
 * 반드시 이 함수로 날을 가르고, 0 으로 «메우지» 않는다.
 *
 * ★2026-08-07 전수 실측(4,724,607행)이 «결측»이라 부르던 것의 정체를 밝혔다 —
 *   o·h·l 이 0/NULL 인 173,085행 중
 *   ·<b>170,454행은 거래정지일</b>이다. `vol=0`·`amt=0` 이고 `c` 는 직전가를 이월한 값이다
 *     (실측 000030 이 2019-01-09~18 내내 14800). 원장 결함이 아니라 <b>시장 사실</b>이다.
 *     `vol=0` 인데 `h>0` 인 행은 <b>0건</b>이라 「거래량 없음」과 「고저 없음」은 같은 날을 가리킨다.
 *   ·<b>2,631행만이 진짜 결측</b>이다 — 거래는 있었는데(`vol>0`) o/h/l 이 없다
 *     (대부분 `src='n'` 네이버 보강분 · 당일치라 아직 고저가 안 들어온 것).
 *
 * 반환 — 'ok' 정상 · 'halt' 거래정지 · 'gap' 진짜 결측
 *   ★<b>'halt' 는 «건너뛴다»</b>(2026-08-07 사용자 선택) — 그 날은 체결이 불가능했으니
 *     판정에서 빼고 다음 날로 넘어간다. 재개 뒤의 갭은 그 날 o/h/l 에 그대로 담긴다.
 *     ⛔신호를 통째로 빼지 않는다 — 그러면 「정지된 적 있는 종목」이 표본에서 사라져
 *     결과가 좋은 쪽으로 기운다(생존편향 · §9).
 *   ★'gap' 은 «판정 불가»다 — 알 수 없는 것을 0 으로 적지 않는다.
 *
 * ★부르는 쪽은 `vol` 을 <b>반드시 함께 읽는다</b> — 그것이 halt 와 gap 을 가르는 유일한 자다.
 *   o/h/l 은 안 읽어 왔으면 묻지 않는다(dbofill 처럼 `h` 만 필요한 잡이 있다).
 */
function qm_day_kind(?array $r): string
{
    if (!$r) return 'gap';
    if (!array_key_exists('vol', $r)) {
        throw new RuntimeException('qm_day_kind: SELECT 에 vol 을 함께 넣어야 한다 (halt/gap 을 가르는 자)');
    }
    if ($r['vol'] === null || (float)$r['vol'] <= 0) return 'halt';
    foreach (['o', 'h', 'l'] as $k) {
        if (array_key_exists($k, $r) && ($r[$k] === null || (float)$r[$k] <= 0)) return 'gap';
    }
    return 'ok';
}

/** 그 날의 고·저·시가를 그대로 믿어도 되는가 (= 'ok' 인가) — 옛 이름을 지킨다 */
function qm_ohlc_ok(?array $r): bool
{
    return qm_day_kind($r) === 'ok';
}

/** Welch t — 표본이 크면 |t|>2 가 관행적 눈금이다. «유의»를 단정하지 않는다 */
function qm_welch(array $a, array $b): ?float
{
    if (($a['n'] ?? 0) < 2 || ($b['n'] ?? 0) < 2) return null;
    $s = sqrt($a['se'] ** 2 + $b['se'] ** 2);
    return $s > 0 ? ($a['mean'] - $b['mean']) / $s : null;
}

/** 한 줄 — 표본 수를 «항상» 함께 적고, 30 미만이면 결론 보류를 붙인다 (§9) */
function qm_line(string $label, array $s): void
{
    if (!$s['n']) { say(sprintf('    %-22s n=0', $label)); return; }
    say(sprintf('    %-22s n=%6d  평균 %7.2f%%  중앙 %7.2f%%  25/75 %6.2f/%6.2f  양(+) %5.1f%%%s',
        $label, $s['n'], $s['mean'], $s['med'], $s['p25'], $s['p75'], $s['win'],
        $s['n'] < 30 ? '  ←n<30 결론 보류' : ''));
}

/** ETF 코드 집합 — `all_etf_info` 가 정본이다(etf_update 크론이 채운다) */
function qm_etf_codes(PDO $pdo): array
{
    try {
        return array_flip($pdo->query("SELECT etf_code FROM all_etf_info")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

// ══════════════════════════════════════════════════════════════════════════
//  스키마
// ══════════════════════════════════════════════════════════════════════════
function qm_ensure_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS qm_event (
        code       VARCHAR(10)  NOT NULL COMMENT '6자리 우선',
        d          DATE         NOT NULL COMMENT '급등일',
        name       VARCHAR(64)  NOT NULL DEFAULT '',
        chg_pct    DECIMAL(6,2) NOT NULL,
        close_prc  INT UNSIGNED NOT NULL,
        prev_prc   INT UNSIGNED NOT NULL,
        amt        BIGINT UNSIGNED NOT NULL COMMENT '거래대금(원)',
        list_shrs  BIGINT UNSIGNED NULL COMMENT '이벤트일 상장주식수 스냅샷',
        mkt        CHAR(1)      NOT NULL DEFAULT '',
        win_from   DATE NOT NULL, win_to DATE NOT NULL,
        made_at    DATETIME NOT NULL,
        PRIMARY KEY (code, d), KEY ix_d (d)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qm_task (
        code   VARCHAR(10) NOT NULL,
        d      DATE        NOT NULL,
        state  TINYINT NOT NULL DEFAULT 0 COMMENT '0대기 1진행 2완료 9포기',
        bars   SMALLINT NOT NULL DEFAULT 0,
        tries  TINYINT  NOT NULL DEFAULT 0,
        err    VARCHAR(255) NULL,
        lock_at DATETIME NULL COMMENT 'state=1 진입 시각 (stale 회수용)',
        upd_at DATETIME NOT NULL,
        PRIMARY KEY (code, d),
        KEY ix_state (state, d), KEY ix_code_state (code, state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ★prune 대상이 아니다 — 만료 삭제를 붙이지 않는다 */
    $pdo->exec("CREATE TABLE IF NOT EXISTS qm_bar (
        code VARCHAR(10) NOT NULL,
        ts   DATETIME    NOT NULL COMMENT 'KST · 봉 «시작» 시각',
        o INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL,
        l INT UNSIGNED NOT NULL, c INT UNSIGNED NOT NULL,
        v BIGINT UNSIGNED NOT NULL DEFAULT 0,
        fetched_at DATETIME NOT NULL COMMENT '수정주가 기준 시점',
        PRIMARY KEY (code, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qm_feat (
        code VARCHAR(10) NOT NULL, d DATE NOT NULL,
        f_hit10_hm      VARCHAR(5)   NULL COMMENT '+10% 최초 도달 HH:MM',
        f_open_gap      DECIMAL(6,2) NULL COMMENT '시가/전일종가-1 (%)',
        f_vol30_ratio   DECIMAL(6,3) NULL COMMENT '첫 30분 거래량 / 당일 거래량',
        f_close_vs_vwap DECIMAL(6,2) NULL,
        f_high_hm       VARCHAR(5)   NULL,
        f_close_vs_high DECIMAL(6,2) NULL COMMENT '종가/고가-1 (%) · 상단 마감 지표',
        f_intraday_mdd  DECIMAL(6,2) NULL COMMENT '고가 이후 최대 되돌림 (%)',
        f_rebreak_n     TINYINT      NULL COMMENT '10% 이탈 후 재돌파 횟수',
        f_pre_vol_mult  DECIMAL(8,3) NULL COMMENT '이벤트일 거래량 / 직전 4일 평균',
        f_pre_ret       DECIMAL(6,2) NULL,
        f_nd_open_ret   DECIMAL(6,2) NULL COMMENT '익일 시가 / 이벤트일 종가-1 (%)',
        f_nd_high_ret   DECIMAL(6,2) NULL,
        f_nd_close_ret  DECIMAL(6,2) NULL,
        f_d5_ret        DECIMAL(6,2) NULL,
        f_post_mdd      DECIMAL(6,2) NULL,
        q_bars_full   TINYINT NOT NULL DEFAULT 0 COMMENT '구간 10거래일에 봉이 «다 있으면» 1 (봉 수가 아니라 날짜 기준)',
        q_halted      TINYINT NOT NULL DEFAULT 0 COMMENT '구간 내 거래정지/상한 의심',
        q_split_after TINYINT NOT NULL DEFAULT 0 COMMENT '이벤트 후 상장주식수 변동',
        q_px_basis    TINYINT NOT NULL DEFAULT 0 COMMENT '분봉(수정주가)과 krx_amt 가격 기준이 어긋남',
        n_pre         TINYINT NOT NULL DEFAULT 0 COMMENT '실제로 쓴 사전 거래일 수 (D-4~D-1 이면 4)',
        n_post        TINYINT NOT NULL DEFAULT 0 COMMENT '★실제로 쓴 사후 거래일 수 — 5 미만이면 f_d5_ret 은 5일치가 아니다',
        g_pre_hm      VARCHAR(5)   NULL COMMENT '이벤트일 «정규장» 마지막 봉 시각(대개 15:19)',
        g_c1519       INT UNSIGNED NULL COMMENT '그 봉의 종가 — 판정을 미래 없이 재현하는 기준가',
        g_auc_ret     DECIMAL(6,2) NULL COMMENT '종가단일가 이동 = 종가/g_c1519-1 (%) · 단일가 봉이 없으면 NULL',
        g_cvh_1519    DECIMAL(6,2) NULL COMMENT '15:19 기준 상단마감 = g_c1519/(그때까지 고가)-1 (%)',
        g_real_ret    DECIMAL(6,2) NULL COMMENT '★실전 왕복 = 익일시가/g_c1519-1 (%)',
        g_nd_has_open TINYINT NOT NULL DEFAULT 0 COMMENT '익일 첫 봉이 09:00 인가 — 0 이면 시가 단일가에 체결이 없었다',
        g_nd_open_vr  DECIMAL(6,3) NULL COMMENT '익일 첫 봉 거래량 / 익일 총거래량',
        g_nd_o1_ret   DECIMAL(6,2) NULL COMMENT '익일 첫 봉 종가/시가-1 (%) ★아래 넷은 모두 «시가 대비»다',
        g_nd_0905_ret DECIMAL(6,2) NULL COMMENT '익일 09:05 종가/시가-1 (%)',
        g_nd_0930_ret DECIMAL(6,2) NULL COMMENT '익일 09:30 종가/시가-1 (%)',
        g_nd_oh_ret   DECIMAL(6,2) NULL COMMENT '익일 고가/시가-1 (%) — 시가 매도가 최선이었나',
        g_nd_oc_ret   DECIMAL(6,2) NULL COMMENT '익일 종가/시가-1 (%)',
        g_ev_close_vr DECIMAL(6,3) NULL COMMENT '이벤트일 15:30 단일가 거래량 / 그 날 총거래량 — «종가에 살 수 있나»',
        g_ev_l10_vr   DECIMAL(6,3) NULL COMMENT '이벤트일 15:10~15:19 거래량 / 그 날 총거래량',
        made_at DATETIME NOT NULL,
        PRIMARY KEY (code, d)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 이미 있던 표를 넓힌다 (MariaDB 라 몇 번을 돌려도 안전하다 — KrxAmt::ensureTable 과 같은 꼴)
    foreach ([
        "q_px_basis TINYINT NOT NULL DEFAULT 0 COMMENT '분봉(수정주가)과 krx_amt 가격 기준이 어긋남'",
        "n_pre      TINYINT NOT NULL DEFAULT 0 COMMENT '실제로 쓴 사전 거래일 수'",
        "n_post     TINYINT NOT NULL DEFAULT 0 COMMENT '★실제로 쓴 사후 거래일 수'",
        // ── 갭 체결 검정(job=gap) — 전부 «분봉 안에서만» 계산한다
        "g_pre_hm      VARCHAR(5)   NULL COMMENT '이벤트일 정규장 마지막 봉 시각'",
        "g_c1519       INT UNSIGNED NULL COMMENT '그 봉의 종가 — 미래 없는 판정 기준가'",
        "g_auc_ret     DECIMAL(6,2) NULL COMMENT '종가단일가 이동 = 종가/g_c1519-1'",
        "g_cvh_1519    DECIMAL(6,2) NULL COMMENT '15:19 기준 상단마감'",
        "g_real_ret    DECIMAL(6,2) NULL COMMENT '실전 왕복 = 익일시가/g_c1519-1'",
        "g_nd_has_open TINYINT NOT NULL DEFAULT 0 COMMENT '익일 첫 봉이 09:00 인가'",
        "g_nd_open_vr  DECIMAL(6,3) NULL COMMENT '익일 첫 봉 거래량/익일 총거래량'",
        "g_nd_o1_ret   DECIMAL(6,2) NULL COMMENT '익일 첫 봉 종가/시가-1'",
        "g_nd_0905_ret DECIMAL(6,2) NULL COMMENT '익일 09:05 종가/시가-1'",
        "g_nd_0930_ret DECIMAL(6,2) NULL COMMENT '익일 09:30 종가/시가-1'",
        "g_nd_oh_ret   DECIMAL(6,2) NULL COMMENT '익일 고가/시가-1'",
        "g_nd_oc_ret   DECIMAL(6,2) NULL COMMENT '익일 종가/시가-1'",
        "g_ev_close_vr DECIMAL(6,3) NULL COMMENT '이벤트일 종가단일가 거래량 비중'",
        "g_ev_l10_vr   DECIMAL(6,3) NULL COMMENT '이벤트일 15:10~15:19 거래량 비중'",
    ] as $c) {
        try { $pdo->exec("ALTER TABLE qm_feat ADD COLUMN IF NOT EXISTS {$c}"); }
        catch (Throwable $e) { /* 이미 있으면 넘어간다 */ }
    }
}

// ══════════════════════════════════════════════════════════════════════════
//  이벤트 추출 — 공용 (probe 와 events 가 같은 함수를 본다)
// ══════════════════════════════════════════════════════════════════════════
/**
 * 최근 N거래일의 급등 이벤트를 뽑고, 배제 사유를 <b>세어서</b> 함께 돌려준다.
 *
 * ★probe 와 events 가 이 함수 하나를 본다 — 조사에서 본 숫자와 실제로 담기는 숫자가
 *   달라지면 조사가 거짓말이 된다.
 *
 * @return array ['events'=>[…], 'excl'=>['필터'=>건수], 'days'=>거래일목록, 'raw'=>1차 통과 건수]
 */
function qm_scan_events(PDO $pdo, int $lookbackDays, bool $verbose = false): array
{
    /* 이벤트 후보를 볼 구간 + 그 앞 QM_MIN_HIST 거래일까지 거래일 달력을 만든다 */
    $calFrom = date('Y-m-d', strtotime('-' . ($lookbackDays + QM_MIN_HIST) * 2 + 60 . ' day'));
    $allDays = qm_trading_days($pdo, $calFrom, date('Y-m-d'));
    if (count($allDays) < $lookbackDays + 2) {
        return ['events' => [], 'excl' => [], 'days' => [], 'raw' => 0,
                'err' => 'krx_amt 거래일이 부족합니다 (' . count($allDays) . '일)'];
    }
    $idx  = array_flip($allDays);
    $scan = array_slice($allDays, -$lookbackDays);      // 이벤트를 찾을 날들

    $etf     = qm_etf_codes($pdo);
    $hasMgmt = qm_has_col($pdo, 'all_stock_info', 'is_admin');   // ★probe 가 실제 이름을 답한다

    $rows = [];
    foreach ($scan as $d) {
        $i = $idx[$d];
        if ($i < 1) continue;
        $prev = $allDays[$i - 1];
        $st = $pdo->prepare("
            SELECT a.code, a.d, a.c, a.amt, a.vol, a.list_shrs, a.mkt,
                   p.c AS prev_c, p.list_shrs AS prev_shrs
              FROM krx_amt a
              JOIN krx_amt p ON p.code = a.code AND p.d = ?
             WHERE a.d = ? AND p.c > 0 AND a.c > 0
               AND a.c >= p.c * ? AND a.amt >= ?
        ");
        $st->execute([$prev, $d, 1 + QM_CHG_MIN / 100, QM_AMT_MIN]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;
    }
    $raw = count($rows);

    $names = qm_names($pdo, array_column($rows, 'code'));

    /* 상장 이력 길이 — 이벤트일까지 krx_amt 에 몇 행이 있나 (신규 상장 배제용) */
    $hist = [];
    if ($rows) {
        $codes = array_values(array_unique(array_column($rows, 'code')));
        foreach (array_chunk($codes, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT code, COUNT(*) n FROM krx_amt
                                  WHERE code IN ($in) AND d <= ? GROUP BY code");
            $st->execute(array_merge($chunk, [end($scan)]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $hist[$r['code']] = (int)$r['n'];
        }
    }

    $excl = ['우선주' => 0, '스팩' => 0, 'ETF' => 0, 'ETN' => 0,
             '관리종목' => 0, '분할·병합' => 0, '신규상장' => 0, '이름없음' => 0];
    $out = [];
    foreach ($rows as $r) {
        $code = (string)$r['code'];
        $name = (string)($names[$code] ?? '');

        if (substr($code, -1) !== '0')                    { $excl['우선주']++;   continue; }
        if ($name === '')                                 { $excl['이름없음']++; continue; }
        if (mb_strpos($name, '스팩') !== false)            { $excl['스팩']++;     continue; }
        if (isset($etf[$code]))                           { $excl['ETF']++;      continue; }
        if (stripos($name, 'ETN') !== false)              { $excl['ETN']++;      continue; }
        if (($hist[$code] ?? 0) < QM_MIN_HIST)            { $excl['신규상장']++; continue; }

        $ls = (int)$r['list_shrs']; $ps = (int)$r['prev_shrs'];
        if ($ls > 0 && $ps > 0 && abs($ls / $ps - 1) > QM_SPLIT_TOL) { $excl['분할·병합']++; continue; }

        $prevC = (float)$r['prev_c'];
        $out[] = [
            'code' => $code, 'd' => $r['d'], 'name' => $name,
            'chg_pct'   => round(((float)$r['c'] / $prevC - 1) * 100, 2),
            'close_prc' => (int)$r['c'], 'prev_prc' => (int)$prevC,
            'amt' => (int)$r['amt'], 'list_shrs' => $ls ?: null, 'mkt' => (string)$r['mkt'],
        ];
    }
    if (!$hasMgmt) $excl['관리종목'] = -1;                 // -1 = 판정 불가(미적용)

    return ['events' => $out, 'excl' => $excl, 'days' => $allDays,
            'idx' => $idx, 'scan' => $scan, 'raw' => $raw];
}

// ══════════════════════════════════════════════════════════════════════════
switch ($job) {

// ══════════════════════════════════════════════════════════════════════════
//  job=probe — ★착수 전 조사 (§11). 아무것도 쓰지 않는다
// ══════════════════════════════════════════════════════════════════════════
case 'probe': {
    say('급등주 분봉 아카이브 — 착수 전 조사  (' . date('Y-m-d H:i:s') . ')');
    say('※ 이 job 은 DB 에 아무것도 쓰지 않는다.');

    // ── P1 스키마 ────────────────────────────────────────────────────
    hr('P1  스키마');
    foreach (['krx_amt', 'all_stock_info', 'all_etf_info'] as $tbl) {
        $st = $pdo->prepare("SELECT column_name, column_type, is_nullable, column_comment
                               FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ?
                              ORDER BY ordinal_position");
        $st->execute([$tbl]);
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) { say("  {$tbl} : ★없음"); continue; }
        say("  {$tbl} (" . count($cols) . '컬럼)');
        foreach ($cols as $c) {
            say(sprintf('      %-20s %-18s %s%s', $c['column_name'], $c['column_type'],
                $c['is_nullable'] === 'YES' ? 'NULL ' : '     ',
                $c['column_comment'] ? '— ' . $c['column_comment'] : ''));
        }
    }
    // 관리종목 후보 컬럼을 이름으로 훑는다
    $st = $pdo->query("SELECT table_name, column_name FROM information_schema.columns
                        WHERE table_schema = DATABASE()
                          AND (column_name LIKE '%admin%' OR column_name LIKE '%mgmt%'
                            OR column_name LIKE '%manage%' OR column_name LIKE '%warn%'
                            OR column_name LIKE '%halt%' OR column_name LIKE '%susp%')
                        ORDER BY table_name");
    $mg = $st->fetchAll(PDO::FETCH_ASSOC);
    say('  관리종목/거래정지 후보 컬럼: ' . ($mg ? '' : '★없음 — 이 필터는 미적용으로 남긴다'));
    foreach ($mg as $r) say('      ' . $r['table_name'] . '.' . $r['column_name']);

    // krx_amt 채움 상태
    $r = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT code) c, MIN(d) mn, MAX(d) mx,
                             SUM(c IS NULL) c_null, SUM(list_shrs IS NULL) ls_null,
                             SUM(amt = 0) amt0
                        FROM krx_amt")->fetch(PDO::FETCH_ASSOC);
    say(sprintf('  krx_amt: %s행 · %s종목 · %s ~ %s', number_format($r['n']),
        number_format($r['c']), $r['mn'], $r['mx']));
    say(sprintf('           종가 NULL %s · 상장주식수 NULL %s · 거래대금 0 %s',
        number_format($r['c_null']), number_format($r['ls_null']), number_format($r['amt0'])));
    // 최근 60거래일만 따로 (아카이브가 쓰는 구간의 품질)
    $r2 = $pdo->query("SELECT COUNT(*) n, SUM(list_shrs IS NULL) ls_null FROM krx_amt
                        WHERE d >= (SELECT MAX(d) FROM krx_amt) - INTERVAL 100 DAY")
              ->fetch(PDO::FETCH_ASSOC);
    say(sprintf('           최근 100일: %s행 · 상장주식수 NULL %s', number_format($r2['n']),
        number_format($r2['ls_null'])));
    $n = (int)$pdo->query("SELECT COUNT(*) FROM all_etf_info")->fetchColumn();
    say('  all_etf_info: ' . number_format($n) . '종목 (ETF 배제 목록으로 쓸 수 있다)');

    // ── P2 용량 ──────────────────────────────────────────────────────
    hr('P2  용량');
    $tot = $pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,1)
                          FROM information_schema.tables WHERE table_schema = DATABASE()")
               ->fetchColumn();
    say('  economist73 전체: ' . $tot . ' MB');
    $st = $pdo->query("SELECT table_name, table_rows,
                              ROUND((data_length+index_length)/1024/1024,1) mb
                         FROM information_schema.tables WHERE table_schema = DATABASE()
                        ORDER BY (data_length+index_length) DESC LIMIT 15");
    say(sprintf('      %-32s %12s %9s', '표', '행(추정)', 'MB'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        say(sprintf('      %-32s %12s %9s', $r['table_name'],
            number_format((int)$r['table_rows']), $r['mb']));
    }
    // krx_amt 행당 바이트 → qm_bar 용량 추정의 근거
    $r = $pdo->query("SELECT table_rows, data_length+index_length AS b
                        FROM information_schema.tables
                       WHERE table_schema = DATABASE() AND table_name='krx_amt'")
             ->fetch(PDO::FETCH_ASSOC);
    if ((int)$r['table_rows'] > 0) {
        say(sprintf('  참고 — krx_amt 행당 %.0f바이트 (13컬럼). qm_bar 는 8컬럼이라 더 작다',
            $r['b'] / $r['table_rows']));
    }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM dt_min")->fetchColumn();
    $r = $pdo->query("SELECT table_rows, ROUND((data_length+index_length)/1024/1024,1) mb,
                             ROUND((data_length+index_length)/GREATEST(table_rows,1),0) per
                        FROM information_schema.tables
                       WHERE table_schema = DATABASE() AND table_name='dt_min'")
             ->fetch(PDO::FETCH_ASSOC);
    say(sprintf('  ★실측 근거 — dt_min(분봉 8컬럼·qm_bar 와 같은 모양): %s행 %sMB · 행당 %s바이트',
        number_format($n), $r['mb'], $r['per']));

    // ── P3 표본 ──────────────────────────────────────────────────────
    hr('P3  표본 (최근 40거래일 ≒ 두 달)');
    $sc = qm_scan_events($pdo, 40);
    if (!empty($sc['err'])) { say('  ★' . $sc['err']); break; }
    $ev = $sc['events'];
    say(sprintf('  1차 통과(등락률 ≥%.1f%% · 거래대금 ≥%s억): %s건',
        QM_CHG_MIN, number_format(QM_AMT_MIN / 100000000), number_format($sc['raw'])));
    foreach ($sc['excl'] as $k => $v) {
        say(sprintf('      − %-10s %s', $k, $v < 0 ? '★판정 불가 (컬럼 없음 · 미적용)' : number_format($v) . '건'));
    }
    $codes = array_unique(array_column($ev, 'code'));
    say(sprintf('  ⇒ 이벤트 %s건 · 유니크 종목 %s개', number_format(count($ev)), number_format(count($codes))));

    // (종목,날짜) 과제 수 — 구간 합집합
    $idx = $sc['idx']; $days = $sc['days'];
    $need = [];
    foreach ($ev as $e) {
        $i = $idx[$e['d']] ?? null;
        if ($i === null) continue;
        for ($k = $i - QM_PRE_DAYS; $k <= $i + QM_POST_DAYS; $k++) {
            if ($k < 0 || !isset($days[$k])) continue;
            $need[$e['code'] . '|' . $days[$k]] = 1;
        }
    }
    $nTask = count($need);
    say(sprintf('  ⇒ (종목,날짜) 과제 %s개  [중복 제거 전 %s개 · 합집합으로 %s개 절약]',
        number_format($nTask), number_format(count($ev) * (QM_PRE_DAYS + QM_POST_DAYS + 1)),
        number_format(count($ev) * (QM_PRE_DAYS + QM_POST_DAYS + 1) - $nTask)));
    /* ★거래일당 봉 수는 «추측하지 않고» dt_min 에서 잰다.
     *   (정규장 381분이 상한이고, 거래가 얇은 분은 봉을 만들지 않아 그보다 적다 — 실측 351) */
    $perDay = (float)$pdo->query("SELECT ROUND(COUNT(*)/GREATEST(COUNT(DISTINCT CONCAT(code,DATE(ts))),1),1)
                                    FROM dt_min")->fetchColumn() ?: 351;
    $perRow = (int)($r['per'] ?? 106);
    say(sprintf('  ⇒ qm_bar 추정 %s행 · %s MB   (거래일당 %s봉 · 행당 %s바이트, dt_min 실측 기준)',
        number_format($nTask * $perDay), number_format($nTask * $perDay * $perRow / 1048576, 1),
        number_format($perDay), $perRow));
    /* 콜 수는 «종목의 구간 폭»이 정한다 — 한 종목에 이벤트가 여럿이면 합집합이 넓어진다.
     * 한 콜 ≈ 900봉 ≈ 2.5거래일. base_dt 로 그 구간에 바로 뛴 뒤 되짚는다. */
    $span = [];
    foreach ($ev as $e) {
        $i = $idx[$e['d']] ?? null;
        if ($i === null) continue;
        $span[$e['code']]['mn'] = min($span[$e['code']]['mn'] ?? $i, $i) - 0;
        $span[$e['code']]['mx'] = max($span[$e['code']]['mx'] ?? $i, $i);
    }
    $calls = 0;
    foreach ($span as $c => $s2) {
        $w = ($s2['mx'] + QM_POST_DAYS) - ($s2['mn'] - QM_PRE_DAYS) + 1;
        $calls += min(QM_MAX_CALLS, (int)ceil($w * $perDay / 900) + 1);
    }
    say(sprintf('  ⇒ 키움 콜 추정 %s콜 (콜당 1초 + 종목 사이 1초 = 약 %.1f시간)  ※base_dt 로 구간에 바로 뛰는 경우',
        number_format($calls), ($calls + count($span)) / 3600));

    say('');
    say('  상위 10건 (거래대금 순)');
    usort($ev, fn($a, $b) => $b['amt'] <=> $a['amt']);
    say(sprintf('      %-8s %-10s %-18s %8s %12s', '코드', '날짜', '이름', '등락%', '거래대금(억)'));
    foreach (array_slice($ev, 0, 10) as $e) {
        say(sprintf('      %-8s %-10s %-18s %8.2f %12s', $e['code'], $e['d'],
            mb_strimwidth($e['name'], 0, 18), $e['chg_pct'], number_format($e['amt'] / 100000000)));
    }
    // 거래대금 분포 — §11-① 축소안 ①(하한 상향)의 근거
    $amts = array_column($ev, 'amt');
    sort($amts);
    if ($amts) {
        $q = fn($p) => $amts[(int)floor((count($amts) - 1) * $p)];
        say(sprintf('  거래대금 분포(억): 최소 %s · 25%% %s · 중앙 %s · 75%% %s · 최대 %s',
            number_format($q(0) / 1e8), number_format($q(.25) / 1e8), number_format($q(.5) / 1e8),
            number_format($q(.75) / 1e8), number_format($q(1) / 1e8)));
        $over300 = count(array_filter($amts, fn($a) => $a >= 30000000000));
        say(sprintf('  참고 — 하한을 300억으로 올리면 이벤트 %s건 (지금의 %.0f%%)',
            number_format($over300), $over300 / max(1, count($amts)) * 100));
    }

    // ── P4 키움 ──────────────────────────────────────────────────────
    hr('P4  키움 ka10080 «기준일» 인자 실호출 확인');
    $kw = new Kiwoom($pdo);
    if (!$kw->hasKey()) { say('  ★인증키 없음 (env/kiwoom.inc) — 확인 불가'); break; }
    $probeCode = Dt::cleanCode((string)($_GET['code'] ?? '005930'));
    $base = $days[max(0, count($days) - 30)] ?? date('Y-m-d', strtotime('-45 day'));
    say('  종목 ' . $probeCode . ' · 기준일 후보 ' . $base);

    /* ① 인자 없이 한 콜 — 최신부터 몇 봉이 오나 */
    try {
        $res  = $kw->minuteRaw($probeCode);
        $list = [];
        foreach ($res['body'] as $v) if (is_array($v) && $v && is_array(reset($v))) { $list = $v; break; }
        $ts = array_map(fn($x) => Kiwoom::mapBar($x)['t'] ?? '', $list);
        $ts = array_values(array_filter($ts));
        sort($ts);
        say(sprintf('  ① 인자 없음      → %d봉 · %s ~ %s · cont-yn=%s',
            count($list), $ts ? $ts[0] : '-', $ts ? end($ts) : '-',
            $res['head']['cont-yn'] ?? '-'));
        $baseline = $ts ? $ts[0] : '';
    } catch (Throwable $e) { say('  ① 실패: ' . $e->getMessage()); $baseline = ''; }
    sleep(1);

    /* ②~ 기준일 인자 후보들 — 가장 오래된 봉이 기준일 근처로 «점프»하면 인자가 먹은 것이다 */
    foreach (['base_dt', 'base_dtm', 'strt_dt', 'qry_dt', 'end_dt'] as $key) {
        try {
            $res  = $kw->minuteRaw($probeCode, [$key => str_replace('-', '', $base)]);
            $list = [];
            foreach ($res['body'] as $v) if (is_array($v) && $v && is_array(reset($v))) { $list = $v; break; }
            $ts = array_map(fn($x) => Kiwoom::mapBar($x)['t'] ?? '', $list);
            $ts = array_values(array_filter($ts));
            sort($ts);
            $first = $ts ? $ts[0] : '';
            $hit   = $first !== '' && $baseline !== '' && substr($first, 0, 10) !== substr($baseline, 0, 10);
            say(sprintf('  ② %-9s → %d봉 · %s ~ %s   %s', $key, count($list),
                $first ?: '-', $ts ? end($ts) : '-',
                $hit ? '★먹는다 (구간이 옮겨졌다)' : '무시됨 (①과 같은 구간)'));
        } catch (Throwable $e) {
            say(sprintf('  ② %-9s → 거절: %s', $key, mb_substr($e->getMessage(), 0, 80)));
        }
        sleep(1);
    }

    /* ③ 연속조회로 두 달을 되짚으면 몇 콜인가 — 인자가 없을 때의 실제 비용 */
    $target = $days[max(0, count($days) - 45)] ?? date('Y-m-d', strtotime('-70 day'));
    say('  ③ 연속조회 실측 — ' . $target . ' 까지 되짚는 데 몇 콜인가');
    try {
        $t = microtime(true);
        $r = $kw->minute($probeCode, 1, $target, QM_MAX_CALLS);
        $rows = $r['rows'];
        say(sprintf('     %d콜 · %s봉 · %s ~ %s · %.1f초',
            $r['calls'], number_format(count($rows)),
            $rows ? $rows[0]['t'] : '-', $rows ? end($rows)['t'] : '-',
            microtime(true) - $t));
        if ($rows && substr($rows[0]['t'], 0, 10) > $target) {
            say('     ★' . QM_MAX_CALLS . '콜로도 ' . $target . ' 까지 못 갔다 — 구간을 줄이거나 기준일 인자가 필요하다');
        }
    } catch (Throwable $e) { say('     실패: ' . $e->getMessage()); }

    hr('요약');
    say('  · 총 소요 ' . round(microtime(true) - $t0, 1) . '초');
    say('  · 이 결과로 §11-①(용량 임계 QM_MAX_MB)·②(MAX_CALLS)·③(필터)을 확정한다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=diag — 한 종목이 왜 「봉 0」 이었나를 실호출로 캔다
// ══════════════════════════════════════════════════════════════════════════
case 'diag': {
    $code = Dt::cleanCode((string)($_GET['code'] ?? ''));
    if ($code === '') { say('code=011230 처럼 종목을 지정한다.'); break; }
    $kw = new Kiwoom($pdo);
    if (!$kw->hasKey()) { say('★키움 인증키 없음'); break; }
    $kw->setGap(QM_GAP_SEC);

    $st = $pdo->prepare("SELECT d, state, bars, tries FROM qm_task WHERE code=? ORDER BY d");
    $st->execute([$code]);
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$tasks) { say('그 종목의 과제가 없다.'); break; }
    $minD = $tasks[0]['d']; $maxD = end($tasks)['d'];
    say(sprintf('%s — 과제 %d개 · %s ~ %s', $code, count($tasks), $minD, $maxD));
    say('  상태: ' . json_encode(array_count_values(array_column($tasks, 'state'))));

    // ① base_dt 를 그 구간 끝으로 주고 «한 콜»만 — 무엇이 오는가
    say('');
    say('① base_dt=' . $maxD . ' 한 콜');
    $res  = $kw->minuteRaw($code, ['base_dt' => str_replace('-', '', $maxD)]);
    $list = [];
    foreach ($res['body'] as $v) if (is_array($v) && $v && is_array(reset($v))) { $list = $v; break; }
    $ts = array_values(array_filter(array_map(fn($x) => Kiwoom::mapBar($x)['t'] ?? '', $list)));
    sort($ts);
    say(sprintf('   %d봉 · %s ~ %s · cont-yn=%s · return_code=%s',
        count($list), $ts ? $ts[0] : '-', $ts ? end($ts) : '-',
        $res['head']['cont-yn'] ?? '-', $res['body']['return_code'] ?? '-'));
    say('   원본 첫 항목: ' . json_encode(array_slice((array)($list[0] ?? []), 0, 6), JSON_UNESCAPED_UNICODE));

    // ② work 가 실제로 쓰는 경로 그대로
    say('');
    say('② minute(from=' . $minD . ', base_dt=' . $maxD . ', maxCall=' . QM_MAX_CALLS . ')');
    $t = microtime(true);
    $r = $kw->minute($code, 1, $minD, QM_MAX_CALLS, $maxD);
    $by = Dt::byDay($r['rows']);
    say(sprintf('   %d콜 · %s봉 · %d날짜 · %.1f초', $r['calls'], number_format(count($r['rows'])),
        count($by), microtime(true) - $t));
    if ($by) {
        $ks = array_keys($by);
        say('   받은 날짜: ' . $ks[0] . ' ~ ' . end($ks));
        $want = array_column($tasks, 'd');
        $hit  = array_intersect($ks, $want);
        say('   ★과제와 겹치는 날짜: ' . count($hit) . ' / ' . count($want)
            . ($hit ? '' : '  ← 겹치는 게 없으면 base_dt 가 안 먹은 것이다'));
    }

    // ③ base_dt 없이 같은 호출 — 대조군
    say('');
    say('③ base_dt 없이 한 콜 (대조군)');
    $res2  = $kw->minuteRaw($code);
    $list2 = [];
    foreach ($res2['body'] as $v) if (is_array($v) && $v && is_array(reset($v))) { $list2 = $v; break; }
    $ts2 = array_values(array_filter(array_map(fn($x) => Kiwoom::mapBar($x)['t'] ?? '', $list2)));
    sort($ts2);
    say(sprintf('   %d봉 · %s ~ %s', count($list2), $ts2 ? $ts2[0] : '-', $ts2 ? end($ts2) : '-'));
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=scan — 「기간을 늘리면 표본과 용량이 얼마나 되나」를 <b>쓰지 않고</b> 센다.
//             이벤트 추출은 krx_amt 만 읽으므로 API 콜 0 이다. 늘릴지 말지 정하는 자리.
// ══════════════════════════════════════════════════════════════════════════
case 'scan': {
    $list = array_map('intval', explode(',', (string)($_GET['days'] ?? '40,120,250')));
    /* 실측 원단위 — 지금 쌓인 것에서 그대로 읽는다(추정치를 손으로 적지 않는다) */
    $barsNow = (int)$pdo->query("SELECT COUNT(*) FROM qm_bar")->fetchColumn();
    $mbNow   = (float)$pdo->query("SELECT ROUND((data_length+index_length)/1024/1024,1)
                                     FROM information_schema.tables
                                    WHERE table_schema=DATABASE() AND table_name='qm_bar'")->fetchColumn();
    $taskNow = (int)$pdo->query("SELECT COUNT(*) FROM qm_task")->fetchColumn();
    $perTask = $taskNow ? $barsNow / $taskNow : 365;
    $perBar  = $barsNow ? $mbNow * 1048576 / $barsNow : 70;
    say(sprintf('실측 원단위 — 과제당 %.0f봉 · 봉당 %.0f바이트 (지금 %s과제 %s봉 %sMB)',
        $perTask, $perBar, number_format($taskNow), number_format($barsNow), $mbNow));
    $dbMb = (float)$pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,1)
                                  FROM information_schema.tables WHERE table_schema=DATABASE()")
                       ->fetchColumn();
    say('현재 DB 전체 ' . $dbMb . ' MB · qm_bar 가드 ' . QM_MAX_MB . ' MB');
    say('');
    say(sprintf('  %-8s %10s %10s %10s %12s %12s', '거래일', '이벤트', '종목', '과제', '봉(추정)', '용량(추정)'));

    foreach ($list as $look) {
        if ($look < 5) continue;
        $sc = qm_scan_events($pdo, $look);
        if (!empty($sc['err'])) { say('  ' . $look . '일: ★' . $sc['err']); continue; }
        $ev = $sc['events']; $idx = $sc['idx']; $days = $sc['days'];
        $need = [];
        foreach ($ev as $e) {
            $i = $idx[$e['d']] ?? null;
            if ($i === null) continue;
            for ($k = $i - QM_PRE_DAYS; $k <= $i + QM_POST_DAYS; $k++) {
                if ($k < 0 || !isset($days[$k])) continue;
                $need[$e['code'] . '|' . $days[$k]] = 1;
            }
        }
        $nt = count($need);
        say(sprintf('  %-8s %10s %10s %10s %12s %10.0f MB', $look . '일',
            number_format(count($ev)), number_format(count(array_unique(array_column($ev, 'code')))),
            number_format($nt), number_format($nt * $perTask), $nt * $perTask * $perBar / 1048576));
    }
    say('');
    say('★키움 분봉은 <b>약 1년</b>만 보관한다 — 그보다 오래된 구간은 어떤 파라미터로도 못 받는다.');
    say('  즉 과거는 «지금 아니면 영영» 이고, 미래는 언제 받아도 된다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=horizon — ★키움이 분봉을 «어디까지» 갖고 있나를 실호출로 잰다.
//
//  「약 1년」은 문서의 말이고, 실제 경계는 날마다 밀린다. 그 경계 밖 날짜를 과제로 만들면
//  받지도 못하면서 tries 만 5번 태우고 「포기」로 굳는다(그리고 콜을 버린다).
//  백필 범위를 정하기 «전에» 이걸 먼저 잰다.
// ══════════════════════════════════════════════════════════════════════════
case 'horizon': {
    $kw = new Kiwoom($pdo);
    if (!$kw->hasKey()) { say('★키움 인증키 없음'); break; }
    $kw->setGap(QM_GAP_SEC);
    $code = Dt::cleanCode((string)($_GET['code'] ?? '005930'));
    say('키움 분봉 보관 경계 — 종목 ' . $code . ' · 오늘 ' . date('Y-m-d'));
    say('  base_dt 를 뒤로 물리며 «그 날 근처 봉이 오는가»를 본다.');
    say('');
    say(sprintf('  %-12s %8s %-22s %s', 'base_dt', '봉', '받은 구간', '판정'));

    $days = qm_trading_days($pdo, date('Y-m-d', strtotime('-500 day')), date('Y-m-d'));
    $probe = [];
    foreach ([0, 20, 40, 60, 80, 100, 120, 140, 160, 180, 200, 220, 240, 250, 260, 270] as $back) {
        $i = count($days) - 1 - $back;
        if ($i < 0) break;
        $probe[] = $days[$i];
    }
    $edge = null;
    foreach ($probe as $bd) {
        try {
            $res  = $kw->minuteRaw($code, ['base_dt' => str_replace('-', '', $bd)]);
            $list = [];
            foreach ($res['body'] as $v) if (is_array($v) && $v && is_array(reset($v))) { $list = $v; break; }
            $ts = array_values(array_filter(array_map(fn($x) => Kiwoom::mapBar($x)['t'] ?? '', $list)));
            sort($ts);
            $newest = $ts ? substr(end($ts), 0, 10) : '';
            /* 요청한 기준일과 «같은 날 근처»가 오면 그 날은 아직 살아 있다.
             * 경계를 넘으면 봉이 없거나 훨씬 최근 것만 온다. */
            $ok = $newest !== '' && abs(strtotime($newest) - strtotime($bd)) <= 7 * 86400;
            say(sprintf('  %-12s %8d %-22s %s', $bd, count($list),
                $ts ? substr($ts[0], 0, 10) . ' ~ ' . $newest : '-',
                $ok ? 'OK' : '★없음 — 경계 밖'));
            if ($ok) $edge = $bd; else break;
        } catch (Throwable $e) {
            say(sprintf('  %-12s %8s %-22s %s', $bd, '-', '-', '실패: ' . mb_substr($e->getMessage(), 0, 40)));
            break;
        }
    }
    say('');
    if ($edge) {
        say('★받을 수 있는 가장 오래된 날 ≈ <b>' . $edge . '</b> (' .
            (int)((time() - strtotime($edge)) / 86400) . '일 전)');
        say('  백필은 이 날짜 «이후»로만 잡는다. 그 앞은 과제로 만들지 않는다 — 콜만 버린다.');
    } else {
        say('★경계를 못 찾았다 — 표본을 바꿔 다시 본다.');
    }
    break;
}

// ══════════════════════════════════════════════════════════════════════════
case 'schema': {
    qm_ensure_tables($pdo);
    foreach (['qm_event', 'qm_task', 'qm_bar', 'qm_feat'] as $t) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        say(sprintf('  %-10s ok · %s행', $t, number_format($n)));
    }
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=events — 급등 이벤트 추출 (API 콜 0 · 멱등)
// ══════════════════════════════════════════════════════════════════════════
case 'events': {
    qm_ensure_tables($pdo);
    $look = max(5, (int)($_GET['days'] ?? 40));
    say('급등 이벤트 추출 — 최근 ' . $look . '거래일');

    $sc = qm_scan_events($pdo, $look);
    if (!empty($sc['err'])) { say('★' . $sc['err']); break; }
    $ev = $sc['events']; $idx = $sc['idx']; $days = $sc['days'];

    say(sprintf('  1차 통과 %s건 → 필터 후 %s건', number_format($sc['raw']), number_format(count($ev))));
    foreach ($sc['excl'] as $k => $v) {
        say(sprintf('      − %-10s %s', $k, $v < 0 ? '★미적용(컬럼 없음)' : number_format($v) . '건'));
    }

    $insEv = $pdo->prepare("INSERT INTO qm_event
        (code,d,name,chg_pct,close_prc,prev_prc,amt,list_shrs,mkt,win_from,win_to,made_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE name=VALUES(name), chg_pct=VALUES(chg_pct),
            close_prc=VALUES(close_prc), prev_prc=VALUES(prev_prc), amt=VALUES(amt),
            list_shrs=VALUES(list_shrs), mkt=VALUES(mkt),
            win_from=VALUES(win_from), win_to=VALUES(win_to)");
    /* ★INSERT IGNORE — 구간이 겹치는 이벤트가 흔하다(합집합). 이미 끝난 과제를
     *   대기로 되돌리면 같은 봉을 두 번 받는다. */
    $insTk = $pdo->prepare("INSERT IGNORE INTO qm_task (code,d,state,upd_at) VALUES (?,?,0,NOW())");

    $nEv = 0; $nTk = 0; $shortWin = 0;
    $pdo->beginTransaction();
    foreach ($ev as $e) {
        $i = $idx[$e['d']] ?? null;
        if ($i === null) continue;
        $from = $days[max(0, $i - QM_PRE_DAYS)];
        $toI  = $i + QM_POST_DAYS;
        if (!isset($days[$toI])) { $shortWin++; $toI = count($days) - 1; }   // D+5 미도래
        $to = $days[$toI];

        $insEv->execute([$e['code'], $e['d'], $e['name'], $e['chg_pct'], $e['close_prc'],
                         $e['prev_prc'], $e['amt'], $e['list_shrs'], $e['mkt'], $from, $to]);
        $nEv++;
        for ($k = max(0, $i - QM_PRE_DAYS); $k <= $toI; $k++) {
            $insTk->execute([$e['code'], $days[$k]]);
            $nTk += $insTk->rowCount();
        }
    }
    $pdo->commit();

    say(sprintf('  qm_event %s건 반영 · qm_task 신규 %s개', number_format($nEv), number_format($nTk)));
    if ($shortWin) say('  ※ D+5 미도래 ' . $shortWin . '건 — 그 날짜는 넣지 않았다. 지난 뒤 다시 돌리면 채워진다.');
    $r = $pdo->query("SELECT state, COUNT(*) n FROM qm_task GROUP BY state")->fetchAll(PDO::FETCH_KEY_PAIR);
    say('  qm_task 상태: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=work — 수집 워커
//
//  ★지시는 «종목» 단위, 판정은 «날짜» 단위다.
//    종목 단위로 성공을 판정하면 빈 날짜 구멍이 영원히 안 메워지면서 tries 도 안 오른다.
// ══════════════════════════════════════════════════════════════════════════
case 'work': {
    qm_ensure_tables($pdo);
    $kw = new Kiwoom($pdo);
    if (!$kw->hasKey()) { say('★키움 인증키 없음 (env/kiwoom.inc) — 아카이브는 폴백을 쓰지 않는다.'); break; }
    $kw->setGap(QM_GAP_SEC);                      // 이 인스턴스만 늦춘다 (dt_min 은 그대로 1초)

    /* ★워커는 «한 번에 하나»만 돈다 — 둘이 겹치면 키움 콜이 두 배가 된다(간격을 늦춘 뜻이 사라진다).
     *
     * `GET_LOCK` 을 쓰는 이유: MariaDB 의 세션 단위 자문 락이라 <b>연결이 끊기면 저절로 풀린다</b>.
     * 이 서버는 공유 호스팅이라 오래 도는 CLI 가 강제 종료될 수 있는데, 그때 락이 남아 있으면
     * 되살리러 온 크론까지 막혀 밤새 아무것도 안 도는 일이 생긴다. 파일 락·DB 플래그로는
     * 그 뒤처리를 사람이 해야 한다. (env/cnt.inc 는 PERSISTENT 를 쓰지 않아 안전하다)
     *
     * 그래서 이 잡은 <b>백그라운드 실행과 크론을 동시에 걸어도</b> 안전하다 —
     * 살아 있으면 크론이 그냥 물러나고, 죽어 있으면 크론이 이어받는다. */
    $got = (int)$pdo->query("SELECT GET_LOCK('qm_work', 0)")->fetchColumn();
    if (!$got) { say('다른 워커가 이미 돌고 있다 — 물러난다 (중복 실행 금지).'); break; }

    say('콜 간격 ' . $kw->gap() . '초 · 차수 예산 ' . QM_ROUND_SEC . '초 · 워커 락 확보');

    // ① 죽은 잠금 회수
    $st = $pdo->prepare("UPDATE qm_task SET state=0, upd_at=NOW()
                          WHERE state=1 AND (lock_at IS NULL OR lock_at < NOW() - INTERVAL ? SECOND)");
    $st->execute([QM_STALE_SEC]);
    if ($st->rowCount()) say('stale 회수: ' . $st->rowCount() . '개');

    // ② 용량 가드 — 조용히 호스팅 용량을 채우면 다른 126개 표가 같이 죽는다
    $mb = (float)$pdo->query("SELECT ROUND((data_length+index_length)/1024/1024,1)
                                FROM information_schema.tables
                               WHERE table_schema = DATABASE() AND table_name='qm_bar'")->fetchColumn();
    if ($mb >= QM_MAX_MB) {
        say('★용량 가드 — qm_bar ' . $mb . 'MB ≥ 한계 ' . QM_MAX_MB . 'MB. 수집을 중단한다.');
        break;
    }

    $limit  = (int)($_GET['n'] ?? 0);            // 0 = 예산이 다할 때까지
    $budget = QM_ROUND_SEC;
    $doneCodes = 0; $doneDates = 0; $failDates = 0;

    /* 예산은 둘을 «둘 다» 본다 — 차수 예산(600초)과 bg 자체 예산(900초).
     * bg 예산을 넘기면 자기호출이 끊겨 로그가 조용히 잘린다. */
    while (microtime(true) - $t0 < $budget) {
        if ($limit && $doneCodes >= $limit) break;
        if (cron_bg_over()) { say('bg 시간 예산 도달 — 나머지는 다음 차수에서'); break; }

        /* ③ 대기 과제의 «종목»을 고른다.
         *   ★`tries` 를 «먼저» 본다 — 실패한 종목은 뒤로 보낸다.
         *     날짜순으로만 고르면 실패한 종목이 늘 가장 오래된 날짜를 쥐고 있어
         *     곧바로 다시 뽑히고, 일시적 장애가 지나갈 틈도 없이 5연속 실패해 포기로 굳는다
         *     (2026-08-05 실측 — 12:10 무렵 6종목 124건이 그렇게 한꺼번에 죽었다). */
        $code = $pdo->query("SELECT code FROM qm_task WHERE state=0 ORDER BY tries, d, code LIMIT 1")
                    ->fetchColumn();
        if (!$code) { say('대기 과제 없음 — 수집 완료.'); break; }

        /* ③-2 ★구간을 «잘라» 잡는다 — 종목 전체를 한 번에 잡지 않는다.
         *      가장 오래된 대기 날짜에서 QM_CHUNK_DAYS 거래일만큼만 가져간다. */
        $st = $pdo->prepare("SELECT MIN(d) FROM qm_task WHERE code=? AND state=0");
        $st->execute([$code]);
        $chunkFrom = (string)$st->fetchColumn();
        if ($chunkFrom === '') continue;

        $st = $pdo->prepare("SELECT d FROM krx_amt WHERE d >= ? AND amt > 0
                              GROUP BY d ORDER BY d LIMIT 1 OFFSET ?");
        $st->execute([$chunkFrom, QM_CHUNK_DAYS - 1]);
        $chunkTo = (string)$st->fetchColumn();
        if ($chunkTo === '') $chunkTo = '9999-12-31';          // 남은 거래일이 그보다 적다

        /* ★자른 자리가 이벤트 창 한가운데면 그 창 끝까지 늘린다 (창을 쪼개지 않는다) */
        $st = $pdo->prepare("SELECT MAX(win_to) FROM qm_event
                              WHERE code=? AND win_from <= ? AND win_to > ?");
        $st->execute([$code, $chunkTo, $chunkTo]);
        $ext = (string)$st->fetchColumn();
        if ($ext !== '' && $ext > $chunkTo) $chunkTo = $ext;

        $lock = $pdo->prepare("UPDATE qm_task SET state=1, lock_at=NOW(), upd_at=NOW()
                                WHERE code=? AND d BETWEEN ? AND ? AND state=0");
        $lock->execute([$code, $chunkFrom, $chunkTo]);
        if (!$lock->rowCount()) continue;        // 다른 차수가 먼저 잡았다 — 다시 고른다

        /* ★같은 구간의 이미 끝난 날짜(state=2)도 함께 잡는다.
         *   아래 ⑤ 가 <b>그 구간</b>의 봉을 지우고 다시 쓰기 때문이다.
         *   대기분만 잡으면 지운 뒤 다시 넣는 것이 대기분뿐이라
         *   **먼저 끝난 날짜는 state=2 인 채 봉만 사라진다**(2026-08-05 실측 179건).
         *   위 UPDATE 가 이미 이 (종목,구간)을 원자적으로 차지했으므로 경합하지 않는다. */
        $pdo->prepare("UPDATE qm_task SET state=1, lock_at=NOW(), upd_at=NOW()
                        WHERE code=? AND d BETWEEN ? AND ? AND state=2")
            ->execute([$code, $chunkFrom, $chunkTo]);

        $st = $pdo->prepare("SELECT d, tries FROM qm_task
                              WHERE code=? AND state=1 AND d BETWEEN ? AND ? ORDER BY d");
        $st->execute([$code, $chunkFrom, $chunkTo]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$tasks) continue;
        $want = array_column($tasks, 'tries', 'd');
        $minD = array_key_first($want);
        $maxD = array_key_last($want);

        $left = (int)$pdo->query("SELECT COUNT(*) FROM qm_task WHERE state=0")->fetchColumn();
        say(sprintf('[%s] %s ~ %s (%d일) … 남은 과제 %s', $code, $minD, $maxD, count($want),
            number_format($left)));

        /* ④ 한 번에 구간 전체를 받는다.
         *   ★base_dt=maxD 로 «그 구간에 바로 뛴다». 없으면 오늘부터 되짚어야 해서
         *     두 달 전 이벤트 하나에 20콜이 든다(005930 실측 2026-08-05). */
        try {
            $r    = $kw->minute($code, 1, $minD, QM_MAX_CALLS, $maxD);
            /* ★필요한 날짜만 남긴다. 이벤트가 여럿인 종목은 min~max 사이에 «과제가 아닌 날»이
             *   섞여 오는데, 그것까지 저장하면 용량이 추정을 훌쩍 넘는다. */
            $rows = array_values(array_filter($r['rows'], fn($x) => isset($want[substr($x['t'], 0, 10)])));
            $byDay = Dt::byDay($rows);

            /* ★「하나도 못 받았다」는 «그 날짜에 봉이 없다»가 아니라 «받아오기가 실패했다»다.
             *   키움은 장애 때 예외 없이 HTTP 200 + 빈 목록을 준다(return_code=0). 그걸 날짜별
             *   「봉 0」 으로 적으면, 데이터가 멀쩡히 있는데도 tries 만 쌓다 포기로 굳는다
             *   — 2026-08-05 실측: 삼화전자·OCI홀딩스 등 6종목 124건이 그렇게 죽었는데
             *     같은 요청을 나중에 다시 하니 33/33 날짜가 그대로 왔다.
             *   ⊗반대로 «일부만» 비는 것은 진짜다(거래정지 — 그 날 vol=0). 그래서 전무일 때만 실패로 본다. */
            if (!$rows) throw new RuntimeException('받아 온 봉이 없습니다 (' . $r['calls'] . '콜) — 일시 장애로 보고 재시도');
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 250);
            /* ★이미 봉을 갖고 있는 날짜는 되돌려 놓는다 — 이번 실패는 그 날짜의 잘못이 아니다.
             *   (위에서 state=2 까지 함께 잠갔으므로, 그냥 벌주면 멀쩡한 날짜가 tries 를 쌓다
             *    봉을 가진 채로 「포기」가 된다.) 예외는 DELETE «전»에 나므로 봉은 아직 살아 있다. */
            $keep = $pdo->prepare("UPDATE qm_task t SET t.state=2, t.lock_at=NULL, t.upd_at=NOW()
                                    WHERE t.code=? AND t.state=1
                                      AND EXISTS(SELECT 1 FROM qm_bar b
                                                  WHERE b.code=t.code
                                                    AND b.ts >= t.d AND b.ts < t.d + INTERVAL 1 DAY)");
            $keep->execute([$code]);
            $up = $pdo->prepare("UPDATE qm_task SET state=IF(tries+1 >= ?, 9, 0), tries=tries+1,
                                        err=?, lock_at=NULL, upd_at=NOW()
                                  WHERE code=? AND state=1");
            $up->execute([Dt::MAX_TRIES, $msg, $code]);
            $failDates += $up->rowCount();
            say('   ✗ ' . $msg . ' (되돌림 ' . $keep->rowCount() . ' · 재시도 ' . $up->rowCount() . ')');
            usleep((int)(QM_GAP_SEC * 1000000));
            continue;
        }

        /* ⑤ ★지우는 범위는 «이번에 다시 쓸 구간»과 정확히 같다.
         *   종목 전체를 지우면 다른 구간(이미 끝난 날짜)의 봉이 함께 날아간다.
         *   구간 안에서는 한 번의 호출로 통째로 받아 오므로 수정주가 기준이 섞이지 않고,
         *   구간끼리 기준이 어긋나는 경우는 `q_px_basis` 가 잡아낸다(krx_amt 와 대조). */
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM qm_bar WHERE code=? AND ts BETWEEN ? AND ?")
            ->execute([$code, $minD . ' 00:00:00', $maxD . ' 23:59:59']);
        $ins = $pdo->prepare("INSERT INTO qm_bar (code,ts,o,h,l,c,v,fetched_at)
                              VALUES (?,?,?,?,?,?,?,NOW())
                              ON DUPLICATE KEY UPDATE o=VALUES(o),h=VALUES(h),l=VALUES(l),
                                    c=VALUES(c),v=VALUES(v),fetched_at=VALUES(fetched_at)");
        foreach ($rows as $b) {
            $ins->execute([$code, $b['t'] . ':00', (int)round($b['o']), (int)round($b['h']),
                           (int)round($b['l']), (int)round($b['c']), (int)$b['v']]);
        }

        // ⑥ ★판정은 날짜별로
        $ok  = $pdo->prepare("UPDATE qm_task SET state=2, bars=?, err=?, lock_at=NULL, upd_at=NOW()
                               WHERE code=? AND d=?");
        $bad = $pdo->prepare("UPDATE qm_task SET state=IF(tries+1 >= ?, 9, 0), tries=tries+1,
                                     bars=0, err='봉 0', lock_at=NULL, upd_at=NOW()
                               WHERE code=? AND d=?");
        foreach ($want as $d => $tries) {
            $n = count($byDay[$d] ?? []);
            if ($n > 0) {
                /* partial 은 «실패가 아니다» — 거래가 얇은 종목은 원래 봉이 적다.
                 * 봉 수가 적다는 이유로 재시도하면 영원히 같은 답을 받는다. */
                $ok->execute([$n, $n >= Dt::BARS_OK ? null : 'partial', $code, $d]);
                $doneDates++;
            } else {
                $bad->execute([Dt::MAX_TRIES, $code, $d]);
                $failDates++;
            }
        }
        $pdo->commit();

        $full = count(array_filter($want, fn($x, $d) => count($byDay[$d] ?? []) >= Dt::BARS_OK,
                      ARRAY_FILTER_USE_BOTH));
        say(sprintf('   ✓ %d콜 · %s봉 · 날짜 %d/%d (온전 %d)', $r['calls'],
            number_format(count($rows)), count($byDay), count($want), $full));
        $doneCodes++;
        usleep((int)(QM_GAP_SEC * 1000000));      // 종목 사이도 같은 간격
    }

    $pdo->query("SELECT RELEASE_LOCK('qm_work')");   // 연결이 끊겨도 풀리지만 명시해 둔다

    hr('차수 종료');
    say(sprintf('  종목 %d · 날짜 성공 %d · 실패 %d · %.1f초',
        $doneCodes, $doneDates, $failDates, microtime(true) - $t0));
    $r = $pdo->query("SELECT state, COUNT(*) n FROM qm_task GROUP BY state ORDER BY state")
             ->fetchAll(PDO::FETCH_KEY_PAIR);
    say('  qm_task 상태(0대기 1진행 2완료 9포기): ' . json_encode($r));
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=trim from=YYYY-MM-DD — ★키움이 못 주는 옛 구간을 «과제에서 덜어낸다».
//
//  받을 수 없는 날짜를 남겨 두면 tries 를 5번 태우고 「포기」로 굳으면서 콜만 버린다.
//  경계는 `job=horizon` 으로 «재고» 넣는다 — 손으로 어림하지 않는다.
//
//  ★이벤트 창이 <b>한 조각이라도</b> 경계 밖이면 그 이벤트를 통째로 뺀다.
//    앞 4일이 잘린 채로 두면 `f_pre_vol_mult`(직전 4일 평균)가 2일 평균이 되면서
//    아무 표시 없이 다른 뜻이 된다 — 그런 조용한 왜곡을 만들지 않는다.
// ══════════════════════════════════════════════════════════════════════════
case 'trim': {
    $from = Dt::cleanDate((string)($_GET['from'] ?? ''));
    if ($from === '') { say('from=YYYY-MM-DD 가 필요하다 (job=horizon 으로 먼저 잰다).'); break; }
    say('키움 보관 경계 ' . $from . ' — 그 앞을 덜어낸다');

    $n = (int)$pdo->query("SELECT COUNT(*) FROM qm_event WHERE win_from < '{$from}'")->fetchColumn();
    say('  경계 밖 이벤트 ' . number_format($n) . '건');

    $st = $pdo->prepare("DELETE FROM qm_event WHERE win_from < ?");
    $st->execute([$from]);
    say('  qm_event 삭제 ' . number_format($st->rowCount()) . '건');

    /* 어느 이벤트 창에도 속하지 않게 된 과제를 지운다 (창은 겹치므로 «남은 이벤트» 기준으로 판정) */
    $st = $pdo->prepare("DELETE t FROM qm_task t
                          WHERE NOT EXISTS (SELECT 1 FROM qm_event e
                                             WHERE e.code = t.code AND t.d BETWEEN e.win_from AND e.win_to)");
    $st->execute();
    say('  qm_task 삭제 ' . number_format($st->rowCount()) . '개');

    $st = $pdo->prepare("DELETE b FROM qm_bar b
                          WHERE NOT EXISTS (SELECT 1 FROM qm_task t
                                             WHERE t.code = b.code AND t.d = DATE(b.ts))");
    $st->execute();
    say('  qm_bar  삭제 ' . number_format($st->rowCount()) . '행 (쓸 데 없어진 봉)');

    $st = $pdo->prepare("DELETE FROM qm_feat WHERE NOT EXISTS
                          (SELECT 1 FROM qm_event e WHERE e.code=qm_feat.code AND e.d=qm_feat.d)");
    $st->execute();
    say('  qm_feat 삭제 ' . number_format($st->rowCount()) . '건');

    $r = $pdo->query("SELECT state, COUNT(*) n FROM qm_task GROUP BY state ORDER BY state")
             ->fetchAll(PDO::FETCH_KEY_PAIR);
    say('  qm_task 상태: ' . json_encode($r));
    $r = $pdo->query("SELECT COUNT(*) n, MIN(d) mn, MAX(d) mx FROM qm_event")->fetch(PDO::FETCH_ASSOC);
    say('  qm_event ' . number_format($r['n']) . '건 · ' . $r['mn'] . ' ~ ' . $r['mx']);
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=reset — 손상·오판 과제를 대기로 되돌린다 (검증이 잡아낸 것을 고치는 손)
//
//  ★「거래정지라서 봉이 없는 날」과 「받아오기가 실패한 날」을 <b>가른다</b>.
//    가르는 자는 `krx_amt.vol` 이다 — 그 날 거래가 있었으면 분봉도 있어야 한다.
// ══════════════════════════════════════════════════════════════════════════
case 'reset': {
    // ① 완료인데 봉이 없는 과제 (종목 통째 삭제에 휩쓸린 것)
    $st = $pdo->prepare("UPDATE qm_task t SET t.state=0, t.tries=0, t.bars=0,
                                t.err='봉 유실 — 재수집', t.lock_at=NULL, t.upd_at=NOW()
                          WHERE t.state=2
                            AND NOT EXISTS(SELECT 1 FROM qm_bar b
                                            WHERE b.code=t.code
                                              AND b.ts >= t.d AND b.ts < t.d + INTERVAL 1 DAY)");
    $st->execute();
    say('① 완료인데 봉 없음 → 대기로: ' . $st->rowCount() . '건');

    // ② 포기했지만 그 날 «거래가 있었던» 과제 = 거래정지가 아니다 → 다시 시도한다
    $st = $pdo->prepare("UPDATE qm_task t
                           JOIN krx_amt k ON k.code=t.code AND k.d=t.d
                            SET t.state=0, t.tries=0, t.err='일시 장애로 판단 — 재수집',
                                t.lock_at=NULL, t.upd_at=NOW()
                          WHERE t.state=9 AND k.vol > 0");
    $st->execute();
    say('② 포기했지만 거래량>0 → 대기로: ' . $st->rowCount() . '건');

    // ③ 남은 포기 = 그 날 거래가 없었던 날 (진짜 거래정지)
    $st = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT code) c FROM qm_task WHERE state=9");
    $r = $st->fetch(PDO::FETCH_ASSOC);
    say('③ 남은 포기(거래정지로 판단): ' . $r['n'] . '건 · ' . $r['c'] . '종목 — 이건 정상이다');

    $r = $pdo->query("SELECT state, COUNT(*) n FROM qm_task GROUP BY state ORDER BY state")
             ->fetchAll(PDO::FETCH_KEY_PAIR);
    say('qm_task 상태: ' . json_encode($r));
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=verify — 수집이 끝났다고 판단하기 전에 반드시 통과해야 하는 것
// ══════════════════════════════════════════════════════════════════════════
case 'verify': {
    $fail = 0;
    $chk = function (string $name, bool $pass, string $note = '') use (&$fail) {
        if (!$pass) $fail++;
        say(sprintf('  [%s] %-28s %s', $pass ? 'OK' : '★', $name, $note));
    };

    $st = $pdo->query("SELECT state, COUNT(*) n FROM qm_task GROUP BY state")->fetchAll(PDO::FETCH_KEY_PAIR);
    $chk('state=0 잔여', (int)($st[0] ?? 0) === 0, ($st[0] ?? 0) . '개');
    $chk('state=1 잔여', (int)($st[1] ?? 0) === 0, ($st[1] ?? 0) . '개 (있으면 stale 회수 실패)');
    /* ★「완료인데 봉이 없다」 — 이 검사가 없으면 손실이 조용히 통과한다.
     *   실제로 그랬다(2026-08-05 · 179건). 종목 봉을 통째로 지우면서 이미 끝난 날짜를
     *   함께 잠그지 않아, 지운 뒤 다시 넣은 것이 «대기분»뿐이었다. */
    /* ★날짜 대조는 «범위»로 적는다 — `DATE(b.ts)=t.d` 는 봉마다 함수를 씌우느라
     *   qm_bar 의 PK(code,ts)를 못 타고 1,900만 행을 통째로 훑는다. 실제로 그 형태의
     *   `job=reset` 이 900초 예산 안에 못 끝나고 죽었다(2026-08-06 · 봉 1,900만 시점).
     *   범위로 바꾸면 PK 구간 탐색이 되고 뜻은 똑같다(ts 는 DATETIME). */
    $orphan = (int)$pdo->query("SELECT COUNT(*) FROM qm_task t WHERE t.state=2
                                  AND NOT EXISTS(SELECT 1 FROM qm_bar b
                                                  WHERE b.code=t.code
                                                    AND b.ts >= t.d AND b.ts < t.d + INTERVAL 1 DAY)")
                       ->fetchColumn();
    $chk('★완료인데 봉이 없음', $orphan === 0, $orphan . '건 (job=reset 으로 되돌린다)');

    $n9 = (int)($st[9] ?? 0);
    say(sprintf('  [--] %-28s %d개 — 사람이 눈으로 확인(상장폐지·거래정지면 정상)', 'state=9 포기', $n9));
    /* 포기가 진짜인지 «거래량»으로 가른다 — 그 날 거래가 있었으면 봉도 있어야 한다 */
    $bad9 = (int)$pdo->query("SELECT COUNT(*) FROM qm_task t
                               JOIN krx_amt k ON k.code=t.code AND k.d=t.d
                              WHERE t.state=9 AND k.vol > 0")->fetchColumn();
    $chk('★포기 중 「거래가 있던 날」', $bad9 === 0,
         $bad9 . '건 — 거래정지가 아니라 수집 실패다 (job=reset)');
    if ($n9) {
        $st2 = $pdo->query("SELECT code, d, tries, err FROM qm_task WHERE state=9 ORDER BY code, d LIMIT 30");
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            say(sprintf('        %s %s tries=%d %s', $r['code'], $r['d'], $r['tries'],
                mb_strimwidth((string)$r['err'], 0, 60)));
        }
    }

    // 이벤트일 봉 존재율
    $r = $pdo->query("SELECT COUNT(*) tot,
                             SUM(EXISTS(SELECT 1 FROM qm_bar b
                                         WHERE b.code=e.code
                                           AND b.ts >= e.d AND b.ts < e.d + INTERVAL 1 DAY)) has
                        FROM qm_event e")->fetch(PDO::FETCH_ASSOC);
    $chk('이벤트일 봉 존재', (int)$r['tot'] > 0 && (int)$r['has'] === (int)$r['tot'],
         $r['has'] . '/' . $r['tot'] . ' — 없는 이벤트는 분석 불가(q_bars_full=0)');

    /* ★아래 두 검사는 «분봉(수정주가)» 과 «krx_amt(그 날 값)» 을 견준다.
     *   이벤트 뒤에 무상증자·액면분할이 있었으면 <b>반드시</b> 어긋난다 — 수집 실패가 아니다.
     *   그래서 `qm_feat.q_px_basis=1` 로 «이미 알고 표시해 둔» 것은 빼고 센다.
     *   빼는 게 아니라 옮기는 것이다 — 그 건수는 바로 아래에 따로 보고한다. */
    $skipBasis = "NOT EXISTS(SELECT 1 FROM qm_feat q
                              WHERE q.code=e.code AND q.d=e.d AND q.q_px_basis=1)";

    // 이벤트일 고가 ≥ 종가 검산
    $st = $pdo->query("SELECT e.code, e.d, e.close_prc, MAX(b.h) mh
                         FROM qm_event e JOIN qm_bar b ON b.code=e.code
                                             AND b.ts >= e.d AND b.ts < e.d + INTERVAL 1 DAY
                        WHERE {$skipBasis}
                        GROUP BY e.code, e.d, e.close_prc HAVING mh < e.close_prc LIMIT 20");
    $bad = $st->fetchAll(PDO::FETCH_ASSOC);
    $chk('고가 ≥ 종가 검산', !$bad, $bad ? count($bad) . '건 어긋남(표본)' : '');
    foreach ($bad as $r) say(sprintf('        %s %s 종가 %s > 분봉 최고 %s', $r['code'], $r['d'],
        number_format($r['close_prc']), number_format($r['mh'])));

    /* 분봉 종가 vs krx_amt 종가 — 어긋나면 분할 의심.
     * ★두 컬럼 다 INT UNSIGNED 라 그냥 빼면 <b>음수에서 언더플로로 터진다</b>
     *   (SQLSTATE 22003 · 실제로 터졌다). SIGNED 로 캐스팅해서 뺀다. */
    $st = $pdo->query("SELECT e.code, e.d, e.close_prc, b.c
                         FROM qm_event e
                         JOIN qm_bar b ON b.code=e.code AND b.ts = CONCAT(e.d,' 15:30:00')
                        WHERE ABS(CAST(b.c AS SIGNED) - CAST(e.close_prc AS SIGNED))
                              > e.close_prc * 0.005 AND {$skipBasis} LIMIT 20");
    $mis = $st->fetchAll(PDO::FETCH_ASSOC);
    $chk('종가 일치(±0.5%)', !$mis, $mis ? count($mis) . '건 불일치 — 표시되지 않은 기준 어긋남' : '');
    foreach ($mis as $r) say(sprintf('        %s %s krx %s vs 분봉 %s', $r['code'], $r['d'],
        number_format($r['close_prc']), number_format($r['c'])));

    /* 표시해 둔 것은 «없는 셈» 치지 않는다 — 몇 건인지 여기서 밝힌다 (§9 생존편향 금지) */
    $nb = (int)$pdo->query("SELECT COUNT(*) FROM qm_feat WHERE q_px_basis=1")->fetchColumn();
    $nbc = (int)$pdo->query("SELECT COUNT(DISTINCT code) FROM qm_feat WHERE q_px_basis=1")->fetchColumn();
    say(sprintf('  [--] %-28s %d건 · %d종목 — 수집 실패가 아니라 «수정주가 vs 당시 가격».',
        '가격기준 어긋남(q_px_basis)', $nb, $nbc));
    say('        분봉 안에서만 만든 피처(비율)는 멀쩡하다. 분석에서 포함·제외 두 경우를 다 본다.');

    hr('표본 크기');
    $r = $pdo->query("SELECT COUNT(*) ev, COUNT(DISTINCT code) cd FROM qm_event")->fetch(PDO::FETCH_ASSOC);
    say('  qm_event ' . number_format($r['ev']) . '건 · 유니크 ' . number_format($r['cd']) . '종목');
    $r = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT code) c FROM qm_bar")->fetch(PDO::FETCH_ASSOC);
    say('  qm_bar   ' . number_format($r['n']) . '행 · ' . number_format($r['c']) . '종목');
    $mb = $pdo->query("SELECT ROUND((data_length+index_length)/1024/1024,1)
                         FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='qm_bar'")->fetchColumn();
    say('  qm_bar   ' . $mb . ' MB (한계 ' . QM_MAX_MB . ' MB)');
    say('');
    say($fail ? '★' . $fail . '항목 미통과 — 수집이 끝나지 않았다.' : '전 항목 통과.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=feat — 피처 계산. DB 만 읽는다 · API 콜 0 · 멱등
//
//  ★봉이 없는 구간은 0 으로 채우지 않는다 — NULL 로 두고 q_bars_full=0
// ══════════════════════════════════════════════════════════════════════════
case 'feat': {
    qm_ensure_tables($pdo);
    $evs = $pdo->query("SELECT * FROM qm_event ORDER BY d, code")->fetchAll(PDO::FETCH_ASSOC);
    say('피처 계산 — 이벤트 ' . number_format(count($evs)) . '건');

    $days = qm_trading_days($pdo, date('Y-m-d', strtotime('-1 year')), date('Y-m-d'));
    $idx  = array_flip($days);

    $sel = $pdo->prepare("SELECT ts, o, h, l, c, v FROM qm_bar
                           WHERE code=? AND ts BETWEEN ? AND ? ORDER BY ts");
    $ins = $pdo->prepare("INSERT INTO qm_feat
        (code,d,f_hit10_hm,f_open_gap,f_vol30_ratio,f_close_vs_vwap,f_high_hm,f_close_vs_high,
         f_intraday_mdd,f_rebreak_n,f_pre_vol_mult,f_pre_ret,f_nd_open_ret,f_nd_high_ret,
         f_nd_close_ret,f_d5_ret,f_post_mdd,q_bars_full,q_halted,q_split_after,q_px_basis,
         n_pre,n_post,made_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
         f_hit10_hm=VALUES(f_hit10_hm), f_open_gap=VALUES(f_open_gap),
         f_vol30_ratio=VALUES(f_vol30_ratio), f_close_vs_vwap=VALUES(f_close_vs_vwap),
         f_high_hm=VALUES(f_high_hm), f_close_vs_high=VALUES(f_close_vs_high),
         f_intraday_mdd=VALUES(f_intraday_mdd), f_rebreak_n=VALUES(f_rebreak_n),
         f_pre_vol_mult=VALUES(f_pre_vol_mult), f_pre_ret=VALUES(f_pre_ret),
         f_nd_open_ret=VALUES(f_nd_open_ret), f_nd_high_ret=VALUES(f_nd_high_ret),
         f_nd_close_ret=VALUES(f_nd_close_ret), f_d5_ret=VALUES(f_d5_ret),
         f_post_mdd=VALUES(f_post_mdd), q_bars_full=VALUES(q_bars_full),
         q_halted=VALUES(q_halted), q_split_after=VALUES(q_split_after),
         q_px_basis=VALUES(q_px_basis), n_pre=VALUES(n_pre), n_post=VALUES(n_post),
         made_at=NOW()");

    /* 갭 피처는 «따로» 쓴다 — 위 INSERT 는 자리가 23개라, 거기에 12개를 더 끼우면
     * 자리 하나만 밀려도 조용히 다른 컬럼에 값이 들어간다. 행은 바로 위에서 이미 만들어졌다. */
    $updG = $pdo->prepare("UPDATE qm_feat SET
         g_pre_hm=?, g_c1519=?, g_auc_ret=?, g_cvh_1519=?, g_real_ret=?, g_nd_has_open=?,
         g_nd_open_vr=?, g_nd_o1_ret=?, g_nd_0905_ret=?, g_nd_0930_ret=?,
         g_nd_oh_ret=?, g_nd_oc_ret=?, g_ev_close_vr=?, g_ev_l10_vr=?
       WHERE code=? AND d=?");

    $done = 0; $partial = 0; $basisBad = 0; $shortPost = 0;
    foreach ($evs as $e) {
        $code = $e['code']; $d = $e['d'];
        $sel->execute([$code, $e['win_from'] . ' 00:00:00', $e['win_to'] . ' 23:59:59']);
        $all = $sel->fetchAll(PDO::FETCH_ASSOC);
        $by  = [];
        foreach ($all as $b) $by[substr($b['ts'], 0, 10)][] = $b;

        $i = $idx[$d] ?? null;
        $winDays = [];
        if ($i !== null) {
            for ($k = max(0, $i - QM_PRE_DAYS); $k <= $i + QM_POST_DAYS; $k++) {
                if (isset($days[$k]) && $days[$k] >= $e['win_from'] && $days[$k] <= $e['win_to']) {
                    $winDays[] = $days[$k];
                }
            }
        }
        /* ★「온전한가」는 <b>날짜가 다 있나</b>지 <b>봉이 375개 넘나</b>가 아니다.
         *   §6 이 이미 말한 것과 같은 이유 — 거래가 얇은 종목은 원래 봉이 적다(실측 345봉/일도 정상).
         *   봉 수로 재면 얇은 종목이 통째로 «분석 불가»가 되어, 표본이 조용히 대형주 쪽으로 기운다.
         *   그건 §9 가 금지한 생존편향과 같은 것이다. 얇음 자체는 q_halted 가 따로 신호한다. */
        $full = $winDays ? (int)(count(array_filter($winDays, fn($x) => count($by[$x] ?? []) > 0))
                                 === count($winDays)) : 0;

        $day  = $by[$d] ?? [];
        $f = array_fill_keys(['hit10', 'gap', 'vol30', 'cvwap', 'highhm', 'cvh', 'mdd', 'rebreak',
                              'pvm', 'pret', 'ndo', 'ndh', 'ndc', 'd5', 'pmdd'], null);

        /* ★★기준가는 «분봉에서» 얻는다 — `qm_event.prev_prc`(krx_amt)를 쓰지 않는다.
         *
         * 키움은 `upd_stkpc_tp=1` 이라 <b>지금 기준으로 소급 수정한 주가</b>를 준다.
         * `krx_amt` 는 그 날 있던 그대로의 값이다. 이벤트 뒤에 무상증자·액면분할이 있었으면
         * 두 값이 상수배로 어긋난다 — 실측: 가온전선 ×1.7995 · RF머트리얼즈 ×1.9960.
         *
         * 이때 <b>비율로 만드는 피처는 멀쩡</b>하다(종가/고가, 되돌림 …). 그러나 분봉 가격을
         * krx 가격과 «견주는» 것들 — 시가갭·10% 도달·재돌파·사전수익률 — 은 기준이 섞여
         * 조용히 거짓이 된다. 그래서 직전 거래일의 <b>마지막 분봉 종가</b>를 기준가로 삼는다.
         * 구간이 D-4 부터라 이벤트일에는 언제나 직전 거래일이 있다.
         */
        $prev = 0.0;
        foreach ($winDays as $x) {                      // 이벤트일 «직전» 거래일의 종가
            if ($x >= $d) break;
            $bs = $by[$x] ?? [];
            if ($bs) $prev = (float)end($bs)['c'];
        }
        /* 두 소스가 어긋났나 — <b>양쪽 날을 다 본다</b>.
         *   · 직전일: 수정주가 배율이 걸린 경우가 여기서 드러난다(가온전선 ×1.7995)
         *   · 이벤트일: 배율이 아닌 «봉 누락»은 직전일이 멀쩡해도 이 날만 어긋난다
         *     (우리기술 2026-06-12 — list_shrs 불변인데 krx 는 상한가 17,570, 분봉은 13,880 마감)
         * 한쪽만 보면 나머지 종류를 통째로 놓친다. */
        $basisOff = 0;
        if ($prev <= 0) {
            $prev = (float)$e['prev_prc'];              // 직전일 봉이 없을 때만 krx 로 물러선다
            $basisOff = 1;
        } elseif ((float)$e['prev_prc'] > 0
                  && abs($prev / (float)$e['prev_prc'] - 1) > 0.005) {
            $basisOff = 1;
        }
        if ($day && (float)$e['close_prc'] > 0
            && abs((float)end($day)['c'] / (float)$e['close_prc'] - 1) > 0.005) {
            $basisOff = 1;
        }

        if ($day) {
            // ── 이벤트일 ──
            $open = (float)$day[0]['o'];
            $f['gap'] = $prev > 0 ? round(($open / $prev - 1) * 100, 2) : null;

            $volAll = 0; $pv = 0; $vol30 = 0;
            $hi = 0; $hiT = ''; $trg = $prev * (1 + QM_CHG_MIN / 100);
            foreach ($day as $b) {
                $volAll += (float)$b['v'];
                $pv     += (float)$b['c'] * (float)$b['v'];
                $hm = substr($b['ts'], 11, 5);
                if ($hm < '09:30') $vol30 += (float)$b['v'];
                if ((float)$b['h'] > $hi) { $hi = (float)$b['h']; $hiT = $hm; }
                if ($f['hit10'] === null && (float)$b['h'] >= $trg) $f['hit10'] = $hm;
            }
            $close = (float)end($day)['c'];
            $vwap  = $volAll > 0 ? $pv / $volAll : 0;
            $f['vol30']  = $volAll > 0 ? round($vol30 / $volAll, 3) : null;
            $f['cvwap']  = $vwap > 0 ? round(($close / $vwap - 1) * 100, 2) : null;
            $f['highhm'] = $hiT ?: null;
            $f['cvh']    = $hi > 0 ? round(($close / $hi - 1) * 100, 2) : null;

            // 고가 이후 최대 되돌림
            $seen = false; $mdd = 0;
            foreach ($day as $b) {
                if (!$seen && substr($b['ts'], 11, 5) === $hiT) { $seen = true; continue; }
                if ($seen && $hi > 0) $mdd = min($mdd, ((float)$b['l'] / $hi - 1) * 100);
            }
            $f['mdd'] = round($mdd, 2);

            // 10% 이탈 후 재돌파 횟수
            $above = false; $re = 0;
            foreach ($day as $b) {
                if (!$above && (float)$b['h'] >= $trg) { $above = true; $re++; }
                elseif ($above && (float)$b['c'] < $trg) { $above = false; }
            }
            $f['rebreak'] = max(0, $re - 1);
        }

        // ── 사전 D-4~D-1 ──
        $preV = []; $preFirstOpen = null;
        foreach ($winDays as $x) {
            if ($x >= $d) continue;
            $bs = $by[$x] ?? [];
            if (!$bs) continue;
            if ($preFirstOpen === null) $preFirstOpen = (float)$bs[0]['o'];
            $preV[] = array_sum(array_column($bs, 'v'));
        }
        if ($preV && $day) {
            $avg = array_sum($preV) / count($preV);
            $f['pvm']  = $avg > 0 ? round(array_sum(array_column($day, 'v')) / $avg, 3) : null;
        }
        if ($preFirstOpen > 0) $f['pret'] = round(($prev / $preFirstOpen - 1) * 100, 2);

        // ── 사후 D+1~D+5 ──
        $post = array_values(array_filter($winDays, fn($x) => $x > $d));
        $evClose = $day ? (float)end($day)['c'] : (float)$e['close_prc'];
        if ($post && $evClose > 0) {
            $n1 = $by[$post[0]] ?? [];
            if ($n1) {
                $f['ndo'] = round(((float)$n1[0]['o'] / $evClose - 1) * 100, 2);
                $f['ndh'] = round((max(array_column($n1, 'h')) / $evClose - 1) * 100, 2);
                $f['ndc'] = round(((float)end($n1)['c'] / $evClose - 1) * 100, 2);
            }
            $last = null; $lo = null;
            foreach ($post as $x) {
                $bs = $by[$x] ?? [];
                if (!$bs) continue;
                $last = (float)end($bs)['c'];
                $m = min(array_column($bs, 'l'));
                $lo = $lo === null ? $m : min($lo, $m);
            }
            if ($last !== null) $f['d5']   = round(($last / $evClose - 1) * 100, 2);
            if ($lo   !== null) $f['pmdd'] = round(($lo / $evClose - 1) * 100, 2);
        }

        /* 거래정지·상한 의심 — 구간 안에 봉이 «아주 적은» 날이 있다.
         * ★조용히 빼지 않는다. 플래그로 남기고 분석에서 두 경우를 «둘 다» 본다. */
        $halt = 0;
        foreach ($winDays as $x) {
            $n = count($by[$x] ?? []);
            if ($n > 0 && $n < 30) { $halt = 1; break; }
        }
        // 이벤트 후 상장주식수 변동
        $st = $pdo->prepare("SELECT COUNT(*) FROM krx_amt WHERE code=? AND d>? AND d<=?
                              AND list_shrs > 0 AND ABS(list_shrs/? - 1) > ?");
        $st->execute([$code, $d, $e['win_to'], max(1, (int)$e['list_shrs']), QM_SPLIT_TOL]);
        $split = (int)$e['list_shrs'] > 0 && (int)$st->fetchColumn() > 0 ? 1 : 0;

        /* ══ 갭 체결 검정용 (g_*) — job=gap 이 읽는다 ═══════════════════════════
         *
         * 묻는 것: 8년 일봉이 낸 「상단마감 → 익일시가 +1.78%p」를 <b>실제로 체결할 수 있나</b>.
         * 그 우위와 체결 사이에는 틈이 셋 있고, 분봉만 각각을 잰다.
         *   ① 판정 시점 — f_close_vs_high 는 «종가»가 있어야 나오는데 종가는 15:30 단일가로 정해진다.
         *      15:20 에 주문할 때는 아직 모른다(look-ahead). → 15:19 종가로 다시 판정한 것이 g_cvh_1519.
         *   ② 매수 체결 — 「익일시가 수익률」의 기준점이 이벤트일 «종가»라 종가에 사야 그 값을 얻는다.
         *      15:19 에 시장가로 사면 종가와 얼마나 다른가 = g_auc_ret.
         *   ③ 매도 체결 — 익일 시가 단일가에 «거래 자체가 없을» 수 있다 = g_nd_has_open.
         *
         * ★★여기서 §11(수정주가 ↔ 당시가격) 함정이 없다 — 15:19·15:30·익일 09:00 이 <b>다 같은
         *   소스(분봉)</b>다. 그래서 이 검정만은 q_px_basis 와 무관하게 성립한다. */
        $g = array_fill_keys(['prehm','c19','auc','cvh19','real','vr','o1','r0905','r0930','oh','oc',
                              'evcvr','evl10'], null);
        $gHasOpen = 0;
        if ($day) {
            /* 「종가에 살 수 있나」 — 상한가에 잠기면 막판 거래가 마른다.
             * ★이것은 «체결된 양»이지 «호가 잔량»이 아니다. 내 주문이 소화된다는 보장이 아니라,
             *   그 자리에서 얼마나 손이 바뀌었는지를 잰다. */
            $vAll = array_sum(array_column($day, 'v'));
            if ($vAll > 0) {
                $v1530 = 0; $vL10 = 0;
                foreach ($day as $b) {
                    $hm = substr($b['ts'], 11, 5);
                    if ($hm >= '15:20')                       $v1530 += (float)$b['v'];
                    elseif ($hm >= '15:10' && $hm <= '15:19') $vL10  += (float)$b['v'];
                }
                $g['evcvr'] = round($v1530 / $vAll, 3);
                $g['evl10'] = round($vL10  / $vAll, 3);
            }
            /* 정규장 마지막 봉 — 15:19 를 «찍지» 않는다. 거래가 일찍 끊긴 날은 그 봉이 15:19 가
             * 아니고, 그때의 «15:19 매수»는 허구다. 그래서 실제 시각을 g_pre_hm 에 남겨
             * 분석이 15:19 인 건만 골라 볼 수 있게 한다(§9 — 조용히 빼지 않고 갈라 본다). */
            $hi19 = 0; $c19 = 0; $hm19 = null;
            foreach ($day as $b) {
                $hm = substr($b['ts'], 11, 5);
                if ($hm >= '15:20') break;                 // 종가 단일가 봉은 제외
                if ((float)$b['h'] > $hi19) $hi19 = (float)$b['h'];
                $c19 = (float)$b['c']; $hm19 = $hm;
            }
            if ($c19 > 0) {
                $g['prehm'] = $hm19;
                $g['c19']   = (int)$c19;
                $g['cvh19'] = $hi19 > 0 ? round(($c19 / $hi19 - 1) * 100, 2) : null;
                /* 종가 단일가 봉이 «있을 때만» 그 이동을 적는다. 없으면 마지막 봉이 곧 15:19 라
                 * 0 이 나오는데, 그건 «안 움직였다»가 아니라 «단일가가 없었다»는 뜻이다. */
                $lastHm = substr(end($day)['ts'], 11, 5);
                if ($lastHm >= '15:20') $g['auc'] = round(((float)end($day)['c'] / $c19 - 1) * 100, 2);
            }
        }
        if ($post && ($n1 = $by[$post[0]] ?? [])) {
            $ndOpen  = (float)$n1[0]['o'];
            $gHasOpen = substr($n1[0]['ts'], 11, 5) === '09:00' ? 1 : 0;
            $ndVol   = array_sum(array_column($n1, 'v'));
            if ($ndVol > 0) $g['vr'] = round((float)$n1[0]['v'] / $ndVol, 3);
            if ($g['c19'] > 0 && $ndOpen > 0) $g['real'] = round(($ndOpen / $g['c19'] - 1) * 100, 2);
            if ($ndOpen > 0) {
                /* ★아래 넷은 «시가 대비»다 — 묻는 것이 「시가에 못 팔면 얼마나 잃나」라서
                 *   이벤트일 종가가 아니라 시가를 기준으로 삼는다. */
                $g['o1'] = round(((float)$n1[0]['c'] / $ndOpen - 1) * 100, 2);
                $at = function (string $hm) use ($n1, $ndOpen) {
                    $c = null;
                    foreach ($n1 as $b) { if (substr($b['ts'], 11, 5) > $hm) break; $c = (float)$b['c']; }
                    return $c === null ? null : round(($c / $ndOpen - 1) * 100, 2);
                };
                $g['r0905'] = $at('09:05');
                $g['r0930'] = $at('09:30');
                $g['oh'] = round((max(array_column($n1, 'h')) / $ndOpen - 1) * 100, 2);
                $g['oc'] = round(((float)end($n1)['c'] / $ndOpen - 1) * 100, 2);
            }
        }

        $ins->execute([$code, $d, $f['hit10'], $f['gap'], $f['vol30'], $f['cvwap'], $f['highhm'],
                       $f['cvh'], $f['mdd'], $f['rebreak'], $f['pvm'], $f['pret'], $f['ndo'],
                       $f['ndh'], $f['ndc'], $f['d5'], $f['pmdd'], $full, $halt, $split, $basisOff,
                       count($winDays) - count($post) - 1, count($post)]);
        $updG->execute([$g['prehm'], $g['c19'], $g['auc'], $g['cvh19'], $g['real'], $gHasOpen,
                        $g['vr'], $g['o1'], $g['r0905'], $g['r0930'], $g['oh'], $g['oc'],
                        $g['evcvr'], $g['evl10'], $code, $d]);
        $done++;
        if (!$full)             $partial++;
        if ($basisOff)          $basisBad++;
        if (count($post) < QM_POST_DAYS) $shortPost++;
    }
    say(sprintf('  qm_feat %s건 · 구간 불완전 %s건(q_bars_full=0) · 가격기준 어긋남 %s건(q_px_basis=1)',
        number_format($done), number_format($partial), number_format($basisBad)));
    say(sprintf('  ★사후 %d거래일이 아직 안 찬 이벤트 %s건 — 그 건의 f_d5_ret·f_post_mdd 는 «5일치가 아니다».',
        QM_POST_DAYS, number_format($shortPost)));
    say('  ※ 플래그는 «빼는» 표시가 아니라 «갈라 보라»는 표시다 (§9 생존편향 금지).');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=analyze — §9. qm_feat 만 읽는다.
//
//  ⛔규율: ①생존편향 금지(거래정지·기준어긋남을 조용히 빼지 않고 «둘 다» 본다)
//          ②표본 수를 항상 함께 적는다. 구간 n<30 이면 결론을 내지 않는다
//          ③없는 숫자를 만들지 않는다 — 근거 컬럼과 필터를 함께 출력한다
//          ④투자 의견을 쓰지 않는다
// ══════════════════════════════════════════════════════════════════════════
case 'analyze': {
    $rows = $pdo->query("SELECT f.*, e.name, e.chg_pct, e.amt
                           FROM qm_feat f JOIN qm_event e ON e.code=f.code AND e.d=f.d")
                ->fetchAll(PDO::FETCH_ASSOC);
    say('급등주 분봉 패턴 분석  (' . date('Y-m-d H:i') . ')');
    say('표본: qm_feat ' . number_format(count($rows)) . '건 — 최근 40거래일 · 전일종가 대비 +'
        . QM_CHG_MIN . '% 이상 & 거래대금 ' . number_format(QM_AMT_MIN / 1e8) . '억 이상');
    say('제외: 우선주 19 · 신규상장 29 · 분할 1 · ⛔관리종목은 판정 컬럼이 없어 «미적용»');

    /* 통계 셋은 파일 위쪽 공용 함수를 본다 — danalyze 와 «같은 자»가 계산해야 견줄 수 있다 */
    $stat = 'qm_stat'; $welch = 'qm_welch'; $line = 'qm_line';

    /* 부분집합 셋 — 조용히 빼지 않고 «나란히» 본다.
     *
     * ★★사후창 완전(n_post=5)이 따로 있는 이유 — 마지막 이벤트가 어제라 D+5 가 «아직 오지 않은»
     *   이벤트가 있다. 그것들의 `f_d5_ret` 은 1~4거래일치인데 이름만 D+5 다.
     *   섞어서 평균을 내면 «5일 성과»라는 말이 거짓이 된다(실측 212건 · 표본의 20%).
     *   기다리면 채워진다 — `job=events` → `job=work` 를 D+5 가 지난 뒤 다시 돌리면 된다. */
    $sets = [
        '전체(있는 그대로)' => $rows,
        '사후창 완전(n_post=5)' => array_values(array_filter($rows,
            fn($r) => (int)$r['n_post'] === QM_POST_DAYS)),
        '사후완전 + 정제(기준일치·거래정지 아님·구간완전)' => array_values(array_filter($rows,
            fn($r) => (int)$r['n_post'] === QM_POST_DAYS && (int)$r['q_bars_full'] === 1
                   && (int)$r['q_px_basis'] === 0 && (int)$r['q_halted'] === 0)),
    ];
    say('');
    foreach ($sets as $k => $v) say(sprintf('  %-46s %s건', $k, number_format(count($v))));
    say('  ※ 「전체」의 f_d5_ret·f_post_mdd 에는 <b>사후창이 덜 찬 건</b>이 섞여 있다 — 그 줄은 5일 성과가 아니다.');
    say('    익일(f_nd_*) 지표는 사후 1일만 있으면 되므로 세 집합의 뜻이 같다.');
    say('    세 값이 크게 다르면 그 자체가 결과다 — 어느 한쪽만 보고하지 않는다.');

    $np = $pdo->query("SELECT n_post, COUNT(*) n FROM qm_feat GROUP BY n_post ORDER BY n_post")
              ->fetchAll(PDO::FETCH_KEY_PAIR);
    say('  사후 거래일 수 분포(n_post): ' . json_encode($np));

    $col = fn(array $rs, string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $rs);

    foreach ($sets as $setName => $rs) {
        hr('[' . $setName . '] n=' . count($rs));

        // ── 가설 1 ────────────────────────────────────────────────────
        say('  가설1  상단에서 마감한 급등주는 익일 시가가 좋은가');
        say('         기준 f_close_vs_high(종가/당일고가-1) · 결과 f_nd_open_ret(익일시가/이벤트일종가-1)');
        $hi = array_filter($rs, fn($r) => $r['f_close_vs_high'] !== null && (float)$r['f_close_vs_high'] >= -3);
        $lo = array_filter($rs, fn($r) => $r['f_close_vs_high'] !== null && (float)$r['f_close_vs_high'] <  -3);
        $a = $stat($col($hi, 'f_nd_open_ret')); $b = $stat($col($lo, 'f_nd_open_ret'));
        $line('상단마감(≥-3%)', $a);
        $line('그밖(<-3%)',     $b);
        $t = $welch($a, $b);
        if ($t !== null) say(sprintf('    차이 %+.2f%%p · Welch t=%.2f%s', $a['mean'] - $b['mean'], $t,
            abs($t) > 2 ? '' : '  (|t|≤2 — 차이를 주장하지 않는다)'));

        // ── 가설 2 ────────────────────────────────────────────────────
        say('');
        say('  가설2  +10% 도달이 이를수록 후행 성과가 다른가   결과 f_d5_ret(D+5 종가/이벤트일 종가-1)');
        $buckets = ['09:00~09:29' => ['09:00', '09:30'], '09:30~10:29' => ['09:30', '10:30'],
                    '10:30~11:59' => ['10:30', '12:00'], '12:00~13:59' => ['12:00', '14:00'],
                    '14:00~15:30' => ['14:00', '15:31']];
        $ends = [];
        foreach ($buckets as $bn => [$s0, $s1]) {
            $g = array_filter($rs, fn($r) => $r['f_hit10_hm'] !== null
                 && $r['f_hit10_hm'] >= $s0 && $r['f_hit10_hm'] < $s1);
            $s = $stat($col($g, 'f_d5_ret'));
            $line($bn, $s);
            $ends[] = $s;
        }
        $t = $welch($ends[0], end($ends));               // 가장 이른 구간 vs 가장 늦은 구간
        if ($t !== null) say(sprintf('    양 끝 차이 %+.2f%%p · Welch t=%.2f%s',
            $ends[0]['mean'] - end($ends)['mean'], $t,
            abs($t) > 2 ? '' : '  (|t|≤2 — 차이를 주장하지 않는다)'));
        $nohit = array_filter($rs, fn($r) => $r['f_hit10_hm'] === null);
        $line('도달 기록 없음', $stat($col($nohit, 'f_d5_ret')));
        say('    ※「도달 기록 없음」= 분봉 기준가로는 +10% 에 닿지 않은 건. krx 일봉과 기준이 다른 건이 여기 모인다.');

        // ── 가설 3 ────────────────────────────────────────────────────
        say('');
        say('  가설3  사전 거래량 배수별 5일 수익률   기준 f_pre_vol_mult(이벤트일 거래량/직전 4일 평균)');
        $ends = [];
        foreach ([['~2배', 0, 2], ['2~5배', 2, 5], ['5~10배', 5, 10], ['10~20배', 10, 20], ['20배~', 20, 1e9]] as [$bn, $x0, $x1]) {
            $g = array_filter($rs, fn($r) => $r['f_pre_vol_mult'] !== null
                 && (float)$r['f_pre_vol_mult'] >= $x0 && (float)$r['f_pre_vol_mult'] < $x1);
            $s = $stat($col($g, 'f_d5_ret'));
            $line($bn, $s);
            $ends[] = $s;
        }
        $t = $welch($ends[0], end($ends));
        if ($t !== null) say(sprintf('    양 끝 차이 %+.2f%%p · Welch t=%.2f%s',
            $ends[0]['mean'] - end($ends)['mean'], $t,
            abs($t) > 2 ? '' : '  (|t|≤2 — 차이를 주장하지 않는다)'));

        // ── 가설 4 ────────────────────────────────────────────────────
        say('');
        say('  가설4  이른 몰림(첫 30분 거래량 비중)과 사후 낙폭   기준 f_vol30_ratio · 결과 f_post_mdd');
        $ends = [];
        foreach ([['~10%', 0, .10], ['10~20%', .10, .20], ['20~30%', .20, .30], ['30%~', .30, 9]] as [$bn, $x0, $x1]) {
            $g = array_filter($rs, fn($r) => $r['f_vol30_ratio'] !== null
                 && (float)$r['f_vol30_ratio'] >= $x0 && (float)$r['f_vol30_ratio'] < $x1);
            $s = $stat($col($g, 'f_post_mdd'));
            $line($bn, $s);
            $ends[] = $s;
        }
        $t = $welch($ends[0], end($ends));
        if ($t !== null) say(sprintf('    양 끝 차이 %+.2f%%p · Welch t=%.2f%s',
            $ends[0]['mean'] - end($ends)['mean'], $t,
            abs($t) > 2 ? '' : '  (|t|≤2 — 차이를 주장하지 않는다)'));

        // ── 참고: 결과변수 자체의 분포 ────────────────────────────────
        say('');
        say('  참고  결과변수 분포');
        foreach (['f_nd_open_ret' => '익일 시가', 'f_nd_close_ret' => '익일 종가',
                  'f_d5_ret' => 'D+5 종가', 'f_post_mdd' => '사후 최대낙폭'] as $c => $nm) {
            $line($nm, $stat($col($rs, $c)));
        }
    }

    hr('플래그');
    foreach (['q_bars_full' => '구간 완전', 'q_halted' => '거래정지 의심',
              'q_split_after' => '사후 주식수 변동', 'q_px_basis' => '가격기준 어긋남'] as $c => $nm) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM qm_feat WHERE {$c}=1")->fetchColumn();
        say(sprintf('  %-16s %4d건', $nm, $n));
    }
    say('');
    say('  이 분석은 통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=gap — ★「+1.78%p 를 실제로 체결할 수 있나」
//
//  8년 일봉(job=danalyze)이 낸 것은 <b>통계적 사실</b>이었다:
//    상단마감(f_close_vs_high ≥ −3%) → 익일 시가 +1.78%p · t=33.54 · 8개 연도 예외 0.
//  그런데 그 값은 「이벤트일 «종가»에 사서 익일 «시가»에 판다」를 뜻한다. 일봉은 거기까지다.
//  실제로 그렇게 할 수 있는지는 틈이 셋 있고, <b>분봉만</b> 각각을 잰다 — §9 의 「일봉으론 못 보는 것」.
//
//    ① 판정 시점 : 종가는 15:30 단일가로 «정해진다». 15:20 에 주문할 때 나는 아직 종가를 모른다.
//    ② 매수 체결 : 그 기준점(종가)을 얻으려면 종가 단일가에 사야 한다. 15:19 에 사면 값이 다르다.
//    ③ 매도 체결 : 익일 09:00 시가 단일가에 «거래 자체가 없을» 수 있다.
//
//  ⛔여기서도 규율은 같다 — 표본 수를 항상 적고, 못 잰 것을 «못 쟀다»고 적고, 매매 규칙을 만들지 않는다.
// ══════════════════════════════════════════════════════════════════════════
case 'gap': {
    /* 왕복 비용 가정(%) — 세금+수수료. ★«가정»이라 화면에 값을 함께 찍는다.
     * 이 숫자를 실측으로 바꾸려면 여기 한 줄만 고친다. */
    $costPct = 0.20;

    $rows = $pdo->query("SELECT f.*, e.name, e.chg_pct FROM qm_feat f
                           JOIN qm_event e ON e.code=f.code AND e.d=f.d")->fetchAll(PDO::FETCH_ASSOC);
    $col  = fn(array $rs, string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $rs);
    $num  = fn($v) => number_format((int)$v);

    say('갭 체결 가능성 검정  (' . date('Y-m-d H:i') . ')');
    say('질문: 8년 일봉의 「상단마감 → 익일시가 +1.78%p」를 <b>실제로 체결할 수 있나</b>');
    say('자료: qm_feat ' . $num(count($rows)) . '건 · ★전부 «분봉 안에서만» 계산 —');
    say('      15:19·15:30·익일 09:00 이 다 같은 소스라 §11(수정주가↔당시가격) 함정이 여기엔 없다.');

    // ── G0. 무엇이 없어서 못 재나 ─────────────────────────────────────────
    hr('G0. 표본과 결측 — 못 잰 것을 먼저 적는다');
    $hasND  = array_values(array_filter($rows, fn($r) => (int)$r['n_post'] >= 1 && $r['f_nd_open_ret'] !== null));
    $has19  = array_values(array_filter($hasND, fn($r) => $r['g_c1519'] !== null));
    /* ★실전 가능 표본 = 「이벤트일 15:19 에 값이 있었다」까지다.
     *
     * ⛔익일 09:00 체결 유무(g_nd_has_open)로 «빼지 않는다» — 처음엔 뺐다가 실측으로 뒤집었다.
     *   첫 봉이 09:00 이 아닌 건은 「못 판다」가 아니라 <b>시초가가 늦게 형성된다</b>는 뜻이다
     *   (실측: 첫 봉 09:02 가 196건 · 10:00 이 47건). 가른 자는 krx_amt 다 —
     *   그 날 «분봉 시가/종가» 비 1.0532 와 «krx 시가/종가» 비 1.0527 이 일치했다.
     *   즉 그 늦은 첫 체결이 곧 그 날의 <b>공식 시가</b>이고, 거기서 팔 수 있다.
     *   ★게다가 09:00 에 체결이 없다는 것은 «매도가 없었다»는 뜻이라, 파는 쪽엔 오히려 문제가 아니다.
     *   빼면 그 247건(평균 +11.4%)이 통째로 사라져 결론이 조용히 뒤집힌다. */
    $real   = array_values(array_filter($has19, fn($r) => $r['g_pre_hm'] === '15:19'));
    $noOpen = array_values(array_filter($real, fn($r) => (int)$r['g_nd_has_open'] === 0));
    foreach ([
        ['qm_feat 전체',                              count($rows)],
        ['익일이 있다 (n_post≥1 · 익일 시가 계산됨)',  count($hasND)],
        ['이벤트일 정규장 마지막 봉이 있다',           count($has19)],
        ['  ↳ ★그 봉이 15:19 다 = 실전 가능 표본',     count($real)],
        ['     그중 익일 첫 체결이 09:00 이 아닌 건',   count($noOpen)],
    ] as [$lab, $n]) say(sprintf('  %-46s %7s건', $lab, $num($n)));
    say('');
    say('  ★「15:19 봉이 없다」만 <b>뺀다</b> — 그 날 거래가 일찍 끊겨 «15:19 매수»가 허구인 건이다.');
    say('    익일 첫 체결이 09:00 이 아닌 건은 <b>빼지 않는다</b> — 그때 형성된 값이 곧 그 날 공식 시가고,');
    say('    체결이 없었다는 것은 «매도가 없었다»는 뜻이라 파는 쪽엔 오히려 문제가 아니다.');
    if ($noOpen) qm_line('  그 건의 익일 시가 수익률', qm_stat($col($noOpen, 'f_nd_open_ret')));
    if (!$has19) { say(''); say('  ⛔g_* 피처가 비어 있다 — job=feat 를 먼저 돌린다.'); break; }

    // ── G1. 판정 시점 ─────────────────────────────────────────────────────
    hr('G1. 판정 시점 — 15:19 에 내린 판정이 15:30 판정과 같은가');
    say('  15:30 판정 f_close_vs_high ≥ −3%  ↔  15:19 판정 g_cvh_1519 ≥ −3%');
    say('  ★일봉 결론은 15:30 판정으로 냈다. 그런데 그 시각엔 이미 종가 단일가가 끝나 있다.');
    $cm = [[0, 0], [0, 0]];
    foreach ($real as $r) {
        if ($r['f_close_vs_high'] === null || $r['g_cvh_1519'] === null) continue;
        $a = (float)$r['g_cvh_1519']    >= -3 ? 1 : 0;   // 15:19 (실전에 쓸 수 있는 판정)
        $b = (float)$r['f_close_vs_high'] >= -3 ? 1 : 0; // 15:30 (일봉이 쓴 판정)
        $cm[$a][$b]++;
    }
    $tot = $cm[0][0] + $cm[0][1] + $cm[1][0] + $cm[1][1];
    say('');
    say(sprintf('  %-18s %12s %12s', '', '15:30 상단', '15:30 그밖'));
    say(sprintf('  %-18s %12s %12s', '15:19 상단', $num($cm[1][1]), $num($cm[1][0])));
    say(sprintf('  %-18s %12s %12s', '15:19 그밖', $num($cm[0][1]), $num($cm[0][0])));
    if ($tot) {
        say('');
        say(sprintf('  일치율 %.1f%%  (n=%s)', ($cm[1][1] + $cm[0][0]) / $tot * 100, $num($tot)));
        say(sprintf('  ★15:19 엔 «상단»인데 15:30 엔 아닌 건 %s (%.1f%%) — 사 놓고 조건이 깨진다',
            $num($cm[1][0]), $cm[1][0] / $tot * 100));
        say(sprintf('    15:19 엔 «그밖»인데 15:30 엔 상단인 건 %s (%.1f%%) — 놓친다',
            $num($cm[0][1]), $cm[0][1] / $tot * 100));
    }

    // ── G2. 우위가 실전 판정으로도 남는가 ────────────────────────────────
    hr('G2. 우위가 «실전 판정»으로도 남는가');
    say('  결과변수는 둘이다 — 이상 왕복과 실전 왕복. 차이가 곧 <b>실행 마찰</b>이다.');
    say('    이상 f_nd_open_ret : 이벤트일 «종가» 매수 → 익일 시가 매도   (일봉이 잰 것)');
    say('    실전 g_real_ret    : 이벤트일 «15:19» 매수 → 익일 시가 매도  (실제로 할 수 있는 것)');
    foreach ([
        ['판정 15:30(일봉과 같은 판정) · 결과 이상',  'f_close_vs_high', 'f_nd_open_ret'],
        ['판정 15:30 · 결과 실전',                    'f_close_vs_high', 'g_real_ret'],
        ['★판정 15:19 · 결과 실전  ← 실제로 할 수 있는 것', 'g_cvh_1519', 'g_real_ret'],
    ] as [$lab, $by2, $out]) {
        say('');
        say('  ' . $lab);
        $hi = array_values(array_filter($real, fn($r) => $r[$by2] !== null && (float)$r[$by2] >= -3));
        $lo = array_values(array_filter($real, fn($r) => $r[$by2] !== null && (float)$r[$by2] <  -3));
        $a = qm_stat($col($hi, $out)); $b = qm_stat($col($lo, $out));
        qm_line('상단마감(≥-3%)', $a);
        qm_line('그밖(<-3%)',     $b);
        $t = qm_welch($a, $b);
        if ($t !== null) say(sprintf('      차이 %+.2f%%p · Welch t=%.2f%s',
            ($a['mean'] ?? 0) - ($b['mean'] ?? 0), $t,
            abs($t) > 2 ? '' : '  (|t|≤2 — 차이를 주장하지 않는다)'));
    }
    say('');
    say('  종가 단일가 이동 g_auc_ret (= 종가/15:19종가−1) — 이것이 두 왕복을 가르는 자다');
    qm_line('전체', qm_stat($col($real, 'g_auc_ret')));
    $hiR = array_values(array_filter($real, fn($r) => $r['g_cvh_1519'] !== null && (float)$r['g_cvh_1519'] >= -3));
    qm_line('15:19 상단마감', qm_stat($col($hiR, 'g_auc_ret')));

    // ── G3. ★★「상단마감」의 정체 ────────────────────────────────────────
    hr('G3. ★★「상단마감」은 무엇을 재고 있었나 — 상한가 분해');
    say('  상한가로 마감하면 <b>정의상 종가=고가</b>라 f_close_vs_high=0 이다.');
    say('  즉 그 필터는 「상단에서 마감했다」가 아니라 <b>「상한가였다」</b>를 우회로 재고 있을 수 있다.');
    say('  ★상한가 판정은 qm_event.chg_pct ≥ 29% (제도상 상한 +30% · 호가단위 때문에 정확히 30 이 안 된다)');
    $isLim = fn($r) => (float)$r['chg_pct'] >= 29;
    $isHi  = fn($r) => $r['f_close_vs_high'] !== null && (float)$r['f_close_vs_high'] >= -3;
    $g2    = array_values(array_filter($real, fn($r) => $r['f_close_vs_high'] !== null));
    foreach ([['상단마감', true], ['그밖', false]] as [$lab, $want]) {
        $grp = array_values(array_filter($g2, fn($r) => $isHi($r) === $want));
        $nl  = count(array_filter($grp, $isLim));
        say(sprintf('    %-10s n=%-6s  그중 상한가마감 %s (%.1f%%)', $lab, $num(count($grp)),
            $num($nl), count($grp) ? $nl / count($grp) * 100 : 0));
    }
    say('');
    say('  상한가 × 상단마감 으로 갈라 본 익일 시가 수익률 — <b>분봉 표본</b>');
    foreach ([[1, true], [1, false], [0, true], [0, false]] as [$L, $H]) {
        $grp = array_values(array_filter($g2, fn($r) => ($isLim($r) ? 1 : 0) === $L && $isHi($r) === $H));
        qm_line(($L ? '상한가마감' : '상한가 아님') . ' · ' . ($H ? '상단' : '비상단'),
                qm_stat($col($grp, 'f_nd_open_ret')));
    }
    $a = qm_stat($col(array_values(array_filter($g2, fn($r) => !$isLim($r) && $isHi($r))),  'f_nd_open_ret'));
    $b = qm_stat($col(array_values(array_filter($g2, fn($r) => !$isLim($r) && !$isHi($r))), 'f_nd_open_ret'));
    $t = qm_welch($a, $b);
    if ($t !== null) say(sprintf('    ⇒ <b>상한가를 뺀 뒤</b> 상단마감 효과: %+.2f%%p · Welch t=%.2f%s',
        ($a['mean'] ?? 0) - ($b['mean'] ?? 0), $t, abs($t) > 2 ? '' : '  (|t|≤2)'));

    /* ★같은 분해를 8년 일봉(qm_dday)에도 던진다 — 분봉은 1년이라 한 국면에 갇힌다.
     *   여기서도 같은 모양이면 「국면 탓」이라는 반론이 닫힌다. */
    try {
        $dd = $pdo->query("SELECT chg_pct, f_close_vs_high, f_nd_open_ret FROM qm_dday
                            WHERE q_split=0 AND q_halt=0
                              AND f_close_vs_high IS NOT NULL AND f_nd_open_ret IS NOT NULL")
                  ->fetchAll(PDO::FETCH_ASSOC);
        if ($dd) {
            say('');
            say('  같은 분해를 <b>8년 일봉(qm_dday)</b>에 던지면 — 국면 탓인지 아닌지가 여기서 갈린다');
            foreach ([[1, true], [1, false], [0, true], [0, false]] as [$L, $H]) {
                $grp = array_values(array_filter($dd, fn($r) => ((float)$r['chg_pct'] >= 29 ? 1 : 0) === $L
                       && (((float)$r['f_close_vs_high'] >= -3) === $H)));
                qm_line(($L ? '상한가마감' : '상한가 아님') . ' · ' . ($H ? '상단' : '비상단'),
                        qm_stat($col($grp, 'f_nd_open_ret')));
            }
            $a = qm_stat($col(array_values(array_filter($dd, fn($r) => (float)$r['chg_pct'] < 29 && (float)$r['f_close_vs_high'] >= -3)), 'f_nd_open_ret'));
            $b = qm_stat($col(array_values(array_filter($dd, fn($r) => (float)$r['chg_pct'] < 29 && (float)$r['f_close_vs_high'] <  -3)), 'f_nd_open_ret'));
            $t = qm_welch($a, $b);
            if ($t !== null) say(sprintf('    ⇒ <b>상한가를 뺀 뒤</b> 8년 일봉의 상단마감 효과: %+.2f%%p · Welch t=%.2f',
                ($a['mean'] ?? 0) - ($b['mean'] ?? 0), $t));
            say('    (일봉 원 결론은 +1.78%p · t=33.54 였다 — 이 줄과 견준다)');
        }
    } catch (Throwable $e) { say('  (qm_dday 없음: ' . $e->getMessage() . ')'); }

    // ── G4. 그러면 상한가 마감을 «살» 수 있었나 ──────────────────────────
    hr('G4. 그 상한가 마감을 «살» 수 있었나 — 이벤트일 막판 거래 비중');
    say('  값이 거기 있어도 못 사면 그 수익률은 내 것이 아니다. 종가에 얼마나 손이 바뀌었나를 잰다.');
    foreach ([['상한가마감', 1], ['상한가 아님', 0]] as [$lab, $L]) {
        $grp = array_values(array_filter($real, fn($r) => ($isLim($r) ? 1 : 0) === $L));
        say('    [' . $lab . ']');
        qm_line('  종가단일가 거래량 비중(%)',  qm_stat(array_map(fn($x) => $x === null ? null : $x * 100, $col($grp, 'g_ev_close_vr'))));
        qm_line('  15:10~15:19 거래량 비중(%)', qm_stat(array_map(fn($x) => $x === null ? null : $x * 100, $col($grp, 'g_ev_l10_vr'))));
    }
    /* ★★여기가 이 검정의 급소다 — 「비중이 낮다」가 아니라 «아예 0» 인 건이 얼마나 되나.
     *   종가 단일가에 체결이 0 이면 그 날 종가에 <b>살 수 없었다</b>. 값이 있어도 내 것이 아니다. */
    say('');
    say('  ★종가 단일가에 «거래가 아예 없던» 비율 — 0 이면 그 날 종가에 살 수 없었다');
    foreach ([['상한가마감', 1], ['상한가 아님', 0]] as [$lab, $L]) {
        $grp = array_values(array_filter($real, fn($r) => ($isLim($r) ? 1 : 0) === $L
                                             && $r['g_ev_close_vr'] !== null));
        $z = array_values(array_filter($grp, fn($r) => (float)$r['g_ev_close_vr'] == 0));
        say(sprintf('    %-12s n=%-6s  체결 0 인 건 %s (%.1f%%)', $lab, $num(count($grp)),
            $num(count($z)), count($grp) ? count($z) / count($grp) * 100 : 0));
    }
    /* ★★★역선택 — 「못 산 쪽이 더 좋은가」. 그렇다면 체결 가능성으로 거른 순간 우위가 깎인다. */
    $limSet = array_values(array_filter($real, fn($r) => $isLim($r) && $r['g_ev_close_vr'] !== null));
    if (count($limSet) >= 30) {
        say('');
        say('  ★★상한가마감을 «살 수 있었나»로 갈라 본 익일 시가 수익률');
        $bought = array_values(array_filter($limSet, fn($r) => (float)$r['g_ev_close_vr'] >  0));
        $missed = array_values(array_filter($limSet, fn($r) => (float)$r['g_ev_close_vr'] == 0));
        qm_line('종가에 체결이 있었다(살 수 있었다)', qm_stat($col($bought, 'f_nd_open_ret')));
        qm_line('체결이 0 이었다(못 샀다)',           qm_stat($col($missed, 'f_nd_open_ret')));
        $t = qm_welch(qm_stat($col($missed, 'f_nd_open_ret')), qm_stat($col($bought, 'f_nd_open_ret')));
        if ($t !== null) say(sprintf('    ⇒ 못 산 쪽이 %+.2f%%p 더 좋다 · Welch t=%.2f%s',
            (qm_stat($col($missed, 'f_nd_open_ret'))['mean'] ?? 0)
            - (qm_stat($col($bought, 'f_nd_open_ret'))['mean'] ?? 0),
            $t, abs($t) > 2 ? '  ← 체결 가능성으로 거르면 우위가 깎인다' : '  (|t|≤2)'));
    }

    say('');
    say('  ⛔여기서 멈춘다 — 분봉은 «체결된 양»만 안다. <b>호가 잔량과 시간우선순위는 원장에 없다</b>.');
    say('    상한가에서는 모든 호가가 «같은 값»이라 체결 순서를 시간우선이 정한다.');
    say('    즉 15:20 에 낸 주문은 아침부터 줄 선 잔량 «뒤»인데, 그 줄 길이를 우리는 재지 못한다.');

    // ── G5. 비용을 빼면 ───────────────────────────────────────────────────
    hr('G5. 왕복 비용을 빼면 — ★비용률은 «가정»이다');
    say(sprintf('  가정: 왕복 %.2f%% (세금+수수료). 이 값은 실측이 아니라 상수다 — 바꾸려면 소스 한 줄.', $costPct));
    $sReal = qm_stat($col($hiR, 'g_real_ret'));
    if (($sReal['n'] ?? 0) > 0) {
        say(sprintf('  15:19 상단마감 · 실전 왕복  평균 %+.2f%% → 비용 뒤 %+.2f%%  (n=%s · 양(+) %.1f%%)',
            $sReal['mean'], $sReal['mean'] - $costPct, $num($sReal['n']), $sReal['win']));
        say('  ※ 「양(+) 비율」은 비용 전 기준이다 — 건별로 비용을 빼면 그 비율도 내려간다.');
    }

    // ── G4. 시가에 팔 수 있나 ─────────────────────────────────────────────
    hr('G6. 익일 시가에 «얼마나» 팔 수 있나');
    qm_line('익일 첫 봉 거래량 비중 (g_nd_open_vr)', qm_stat($col($real, 'g_nd_open_vr')));
    say('  ※ 09:00 봉은 시가 단일가 물량이 통째로 실린 봉이다 — 비중이 클수록 그 자리에 팔 여지가 넓다.');
    say('    ⛔이것은 «호가 잔량»이 아니라 «체결된 양»이다. 내 주문이 그만큼 소화된다는 보장은 아니다.');

    hr('G7. 갭의 수명 — 시가에 못 팔면 얼마나 잃나 (전부 «익일 시가 대비»)');
    say('  대상: 15:19 상단마감 · 실전 가능 표본  n=' . $num(count($hiR)));
    foreach (['g_nd_o1_ret' => '09:00 봉 종가', 'g_nd_0905_ret' => '09:05',
              'g_nd_0930_ret' => '09:30', 'g_nd_oc_ret' => '익일 종가',
              'g_nd_oh_ret' => '익일 고가(참고)'] as $c => $lab) {
        qm_line($lab, qm_stat($col($hiR, $c)));
    }
    say('');
    say('  ★「익일 고가」는 사후에만 아는 값이다 — 그 자리에 팔 수 있었다는 뜻이 아니라,');
    say('    시가 매도가 얼마나 손해였는지를 재는 «위쪽 한계»다.');

    say('');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dbuild — ★8년 일봉 검증 적재 (`qm_dday`)
//
//  왜 있나 — 분봉 아카이브는 «키움이 1년만 보관»해서 구조적으로 한 국면에 갇힌다.
//  같은 이벤트 정의를 `krx_amt`(2019~) 에 던지면 코로나 폭락·2022 약세장까지 넘나드는
//  표본이 나온다. <b>API 콜 0</b> — 이미 있는 일봉만 읽는다.
//
//  ⛔`qm_event`/`qm_task` 에 넣지 않는다. 거기 4만 건을 넣으면 job=work 가
//    18만 과제의 «분봉»을 받으러 간다(4.4GB · 며칠). 이 표는 분봉과 무관하다.
// ══════════════════════════════════════════════════════════════════════════
case 'dbuild': {
    $pdo->exec("CREATE TABLE IF NOT EXISTS qm_dday (
        code CHAR(6) NOT NULL, d DATE NOT NULL,
        yr SMALLINT NOT NULL COMMENT '국면별로 가르는 키',
        name VARCHAR(64) NOT NULL DEFAULT '', mkt CHAR(1) NOT NULL DEFAULT '',
        chg_pct DECIMAL(6,2) NOT NULL, close_prc INT UNSIGNED NOT NULL,
        prev_prc INT UNSIGNED NOT NULL, amt BIGINT UNSIGNED NOT NULL,
        f_open_gap      DECIMAL(6,2) NULL COMMENT '시가/전일종가-1',
        f_close_vs_high DECIMAL(6,2) NULL COMMENT '종가/당일고가-1',
        f_range_pct     DECIMAL(6,2) NULL COMMENT '(고-저)/전일종가',
        f_pre_vol_mult  DECIMAL(8,3) NULL COMMENT '거래량/직전 4일 평균',
        f_amt_mult      DECIMAL(8,3) NULL COMMENT '거래대금/직전 20일 평균',
        f_pre_ret       DECIMAL(6,2) NULL COMMENT '전일종가/D-5 종가-1',
        f_nd_open_ret   DECIMAL(6,2) NULL, f_nd_high_ret DECIMAL(6,2) NULL,
        f_nd_close_ret  DECIMAL(6,2) NULL,
        f_d5_ret        DECIMAL(6,2) NULL, f_d20_ret DECIMAL(6,2) NULL,
        f_post_mdd      DECIMAL(6,2) NULL COMMENT 'D+1~D+5 최저/이벤트일 종가-1',
        f_post_mfe      DECIMAL(6,2) NULL COMMENT 'D+1~D+5 최고/이벤트일 종가-1',
        n_post TINYINT NOT NULL DEFAULT 0,
        q_split TINYINT NOT NULL DEFAULT 0 COMMENT '★D~D+20 상장주식수 변동 — 수익률이 거짓이 된다',
        q_halt  TINYINT NOT NULL DEFAULT 0 COMMENT 'D+1~D+5 에 거래 없는 날',
        q_noname TINYINT NOT NULL DEFAULT 0 COMMENT '★이름을 못 찾음(대개 상장폐지) — 스팩/ETN 필터가 적용 안 됨',
        q_ohlc  TINYINT NOT NULL DEFAULT 0 COMMENT '★진짜 결측(거래는 있었는데 o/h/l 없음)이 이벤트일 또는 사후 창에 있음',
        made_at DATETIME NOT NULL,
        PRIMARY KEY (code, d), KEY ix_yr (yr), KEY ix_d (d)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE qm_dday ADD COLUMN IF NOT EXISTS q_noname TINYINT NOT NULL DEFAULT 0"); }
    catch (Throwable $e) {}
    /* ★표가 이미 있으면 CREATE IF NOT EXISTS 는 건너뛴다 — 컬럼 추가는 «prepare 전에» 따로 한다 */
    try { $pdo->exec("ALTER TABLE qm_dday ADD COLUMN IF NOT EXISTS q_ohlc TINYINT NOT NULL DEFAULT 0"); }
    catch (Throwable $e) { say('  (q_ohlc 추가 실패: ' . $e->getMessage() . ')'); }

    $etf   = qm_etf_codes($pdo);
    /* 이름은 두 곳에서 모은다 — all_stock_info(현재 상장) + krx_daily(상장폐지 잔재).
     * ★SQL 조인으로 붙이지 않는다: all_stock_info 는 utf8mb3 라 krx_amt(utf8mb4)와 조인하면
     *   BNL 로 떨어져 실제로 타임아웃했다. PHP 에서 붙인다. */
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info")
                 ->fetchAll(PDO::FETCH_ASSOC) as $r) $names[$r['stock_code']] = $r['stock_name'];
    $n1 = count($names);
    try {
        foreach ($pdo->query("SELECT stock_code, MAX(stock_name) nm FROM krx_daily GROUP BY stock_code")
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($names[$r['stock_code']]) && $r['nm'] !== '') $names[$r['stock_code']] = $r['nm'];
        }
    } catch (Throwable $e) { say('  (krx_daily 이름 보강 실패: ' . $e->getMessage() . ')'); }
    say('일봉 8년 검증 적재 — API 콜 0 · 이름 ' . number_format($n1) . '개 + krx_daily 보강 '
        . number_format(count($names) - $n1) . '개');
    say('★이름을 못 찾아도 «빼지 않는다» — 그 종목 대부분이 상장폐지라, 빼면 결과가 좋은 쪽으로 기운다(생존편향).');

    $codes = $pdo->query("SELECT DISTINCT code FROM krx_amt ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
    say('종목 ' . number_format(count($codes)) . '개를 훑는다…');

    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt,list_shrs,mkt FROM krx_amt
                           WHERE code=? AND c>0 ORDER BY d");
    /* ★ON DUPLICATE 는 «다시 계산한 칸을 전부» 덮는다 — 2026-08-07 이전에는 yr·q_noname 만 갱신해서
     *   계산을 고쳐도 이미 있는 행이 옛 값을 그대로 들고 있었다(다시 채워도 아무 일이 없었다). */
    $ins = $pdo->prepare("INSERT INTO qm_dday
        (code,d,yr,name,mkt,chg_pct,close_prc,prev_prc,amt,
         f_open_gap,f_close_vs_high,f_range_pct,f_pre_vol_mult,f_amt_mult,f_pre_ret,
         f_nd_open_ret,f_nd_high_ret,f_nd_close_ret,f_d5_ret,f_d20_ret,
         f_post_mdd,f_post_mfe,n_post,q_split,q_halt,q_noname,q_ohlc,made_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE yr=VALUES(yr), name=VALUES(name), mkt=VALUES(mkt),
            chg_pct=VALUES(chg_pct), close_prc=VALUES(close_prc), prev_prc=VALUES(prev_prc),
            amt=VALUES(amt), f_open_gap=VALUES(f_open_gap), f_close_vs_high=VALUES(f_close_vs_high),
            f_range_pct=VALUES(f_range_pct), f_pre_vol_mult=VALUES(f_pre_vol_mult),
            f_amt_mult=VALUES(f_amt_mult), f_pre_ret=VALUES(f_pre_ret),
            f_nd_open_ret=VALUES(f_nd_open_ret), f_nd_high_ret=VALUES(f_nd_high_ret),
            f_nd_close_ret=VALUES(f_nd_close_ret), f_d5_ret=VALUES(f_d5_ret),
            f_d20_ret=VALUES(f_d20_ret), f_post_mdd=VALUES(f_post_mdd), f_post_mfe=VALUES(f_post_mfe),
            n_post=VALUES(n_post), q_split=VALUES(q_split), q_halt=VALUES(q_halt),
            q_noname=VALUES(q_noname), q_ohlc=VALUES(q_ohlc), made_at=NOW()");

    $nEv = 0; $nCode = 0; $excl = ['우선주'=>0,'스팩'=>0,'ETF'=>0,'ETN'=>0,'이름없음'=>0,'신규상장'=>0,'분할일'=>0];
    $pdo->beginTransaction();
    foreach ($codes as $ci => $code) {
        // ── 종목 단위 사전 배제 (한 번만 판정한다)
        if (substr($code, -1) !== '0')                { $excl['우선주']++;   continue; }
        if (isset($etf[$code]))                       { $excl['ETF']++;      continue; }
        $nm = (string)($names[$code] ?? '');
        /* ★이름이 없으면 «표시하고 넣는다» — 빼지 않는다.
         *   이름이 없는 종목은 대부분 상장폐지라, 빼는 순간 «끝이 나쁜 것»만 사라져
         *   결과가 좋은 쪽으로 기운다. 그게 §9 가 금지한 생존편향이다. */
        $noName = $nm === '' ? 1 : 0;
        if (!$noName && mb_strpos($nm, '스팩') !== false) { $excl['스팩']++;  continue; }
        if (!$noName && stripos($nm, 'ETN') !== false)    { $excl['ETN']++;  continue; }
        if ($noName) $excl['이름없음']++;

        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $n = count($s);
        if ($n < QM_MIN_HIST + 2) continue;
        $nCode++;

        for ($i = QM_MIN_HIST; $i < $n; $i++) {       // 앞 60거래일은 신규상장으로 보고 건너뛴다
            $cur = $s[$i]; $pre = $s[$i - 1];
            $pc = (float)$pre['c']; $cc = (float)$cur['c'];
            if ($pc <= 0) continue;
            $chg = ($cc / $pc - 1) * 100;
            if ($chg < QM_CHG_MIN || (float)$cur['amt'] < QM_AMT_MIN) continue;

            // 분할·병합일 자체는 등락률이 가짜다 — 배제
            $ls = (int)$cur['list_shrs']; $ps = (int)$pre['list_shrs'];
            if ($ls > 0 && $ps > 0 && abs($ls / $ps - 1) > QM_SPLIT_TOL) { $excl['분할일']++; continue; }

            // ── 사전 ──
            $v4 = []; $a20 = [];
            for ($k = max(0, $i - 4); $k < $i; $k++)  $v4[]  = (float)$s[$k]['vol'];
            for ($k = max(0, $i - 20); $k < $i; $k++) $a20[] = (float)$s[$k]['amt'];
            $vAvg = $v4  ? array_sum($v4)  / count($v4)  : 0;
            $aAvg = $a20 ? array_sum($a20) / count($a20) : 0;
            $c5   = $i >= 5 ? (float)$s[$i - 5]['c'] : 0;

            // ── 사후 (있는 만큼만 · 없으면 NULL) ──
            /* ★날을 «가른 뒤» 잰다 (qm_day_kind 주석) — 거래정지일은 고·저가 아예 없고(0),
             *   그대로 쓰면 저가 −100%·고가 0 이 되어 가짜 폭락이 만들어진다.
             *   정지일·결측일은 최고/최저 계산에서 «빼고», 있었다는 사실만 표시한다. */
            $post = array_slice($s, $i + 1, 5);
            $nPost = count($post);
            $lo = $hi = null; $halt = 0; $gapPost = 0;
            foreach ($post as $p) {
                $dk = qm_day_kind($p);
                if ($dk === 'halt') { $halt = 1;    continue; }
                if ($dk === 'gap')  { $gapPost = 1; continue; }
                $lo = $lo === null ? (float)$p['l'] : min($lo, (float)$p['l']);
                $hi = $hi === null ? (float)$p['h'] : max($hi, (float)$p['h']);
            }
            /* 이벤트일 자체 — 거래대금 하한을 넘겼으니 정지일일 수는 없지만 결측일 수는 있다 */
            $evOk = qm_day_kind($cur) === 'ok';
            /* 「그 날 종가에 팔았다」는 칸들 — 그 날이 정지면 이월된 값이라 «팔 수 없던 값»이다 */
            $p0ok = $nPost >= 1 && qm_day_kind($post[0]) === 'ok';
            $p4ok = $nPost >= 5 && qm_day_kind($post[4]) === 'ok';
            /* ★수정주가가 아니다 — krx_amt 는 «그 날 있던 값»이라 분할이 끼면 수익률이 통째로 거짓이 된다.
             *   D~D+20 안에서 상장주식수가 흔들리면 표시해 둔다(빼지 않는다 · §9). */
            $split = 0;
            for ($k = $i; $k <= min($n - 1, $i + 20); $k++) {
                $x = (int)$s[$k]['list_shrs'];
                if ($ls > 0 && $x > 0 && abs($x / $ls - 1) > 0.05) { $split = 1; break; }
            }
            /* D+20 도 «그 날 팔 수 있었나»를 묻는다 — 정지일이면 이월된 값이라 비운다 */
            $d20 = isset($s[$i + 20]) && qm_day_kind($s[$i + 20]) === 'ok'
                ? (float)$s[$i + 20]['c'] : null;

            $r2 = fn($x) => $x === null ? null : round($x, 2);
            $ins->execute([
                $code, $cur['d'], (int)substr($cur['d'], 0, 4), $nm, (string)$cur['mkt'],
                round($chg, 2), (int)$cc, (int)$pc, (int)$cur['amt'],
                $evOk ? $r2(((float)$cur['o'] / $pc - 1) * 100) : null,
                $evOk ? $r2(($cc / (float)$cur['h'] - 1) * 100) : null,
                $evOk ? $r2((((float)$cur['h'] - (float)$cur['l']) / $pc) * 100) : null,
                $vAvg > 0 ? round((float)$cur['vol'] / $vAvg, 3) : null,
                $aAvg > 0 ? round((float)$cur['amt'] / $aAvg, 3) : null,
                $c5 > 0 ? $r2(($pc / $c5 - 1) * 100) : null,
                $p0ok ? $r2(((float)$post[0]['o'] / $cc - 1) * 100) : null,
                $p0ok ? $r2(((float)$post[0]['h'] / $cc - 1) * 100) : null,
                $p0ok ? $r2(((float)$post[0]['c'] / $cc - 1) * 100) : null,
                $p4ok ? $r2(((float)$post[4]['c'] / $cc - 1) * 100) : null,
                $d20 !== null ? $r2(($d20 / $cc - 1) * 100) : null,
                $lo !== null ? $r2(($lo / $cc - 1) * 100) : null,
                $hi !== null ? $r2(($hi / $cc - 1) * 100) : null,
                $nPost, $split, $halt, $noName, ($gapPost || !$evOk) ? 1 : 0,
            ]);
            $nEv++;
        }
        if ($ci % 400 === 0) { $pdo->commit(); $pdo->beginTransaction(); say('  … ' . $ci . '종목 · 이벤트 ' . number_format($nEv)); }
    }
    $pdo->commit();

    say('');
    say('훑은 종목 ' . number_format($nCode) . ' · 이벤트 ' . number_format($nEv));
    foreach ($excl as $k => $v) if ($v) say(sprintf('  − %-8s %s종목', $k, number_format($v)));
    $r = $pdo->query("SELECT yr, COUNT(*) n FROM qm_dday GROUP BY yr ORDER BY yr")->fetchAll(PDO::FETCH_KEY_PAIR);
    say('  연도별: ' . json_encode($r));
    $qh = $pdo->query("SELECT SUM(q_halt) h, SUM(q_ohlc) g, SUM(f_post_mdd < -99) m FROM qm_dday")
              ->fetch(PDO::FETCH_ASSOC);
    say(sprintf('  ★사후에 거래정지일 %s건 · 진짜 결측 %s건 — 둘 다 «표시하고 남긴다»(빼면 생존편향)',
        number_format((int)$qh['h']), number_format((int)$qh['g'])));
    say('  ★사후 최대낙폭 −99%↓ ' . number_format((int)$qh['m'])
        . '건 — 0 이어야 한다(그것이 정지일을 0 으로 읽던 가짜 폭락이다)');
    $mb = $pdo->query("SELECT ROUND((data_length+index_length)/1024/1024,1) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='qm_dday'")->fetchColumn();
    say('  qm_dday ' . $mb . ' MB');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=danalyze — ★8년 일봉 검증. «국면을 넘는가»가 유일한 질문이다.
// ══════════════════════════════════════════════════════════════════════════
case 'danalyze': {
    $rows = $pdo->query("SELECT * FROM qm_dday")->fetchAll(PDO::FETCH_ASSOC);
    $col  = fn(array $rs, string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $rs);
    say('급등 이벤트 8년 일봉 검증  (' . date('Y-m-d H:i') . ')');
    say('표본 ' . number_format(count($rows)) . '건 — 2019-01-02~ · 전일종가 대비 +' . QM_CHG_MIN
        . '% & 거래대금 ' . number_format(QM_AMT_MIN / 1e8) . '억 · 데이터 `krx_amt` 일봉 · API 콜 0');
    say('제외: 우선주·스팩·ETF·ETN·분할일·상장 60거래일 미만');
    say('★상장폐지 종목을 «빼지 않았다» — 빼면 끝이 나쁜 것만 사라져 결과가 좋아진다(생존편향).');

    /* 깨끗한 표본 — 분할이 낀 건 수익률 자체가 거짓이라 그것만 뺀다.
     * ★q_noname(상장폐지)은 빼지 않는다. 아래에서 «빼면 얼마나 달라지나»를 따로 보여 준다. */
    $clean = array_values(array_filter($rows, fn($r) => (int)$r['q_split'] === 0 && (int)$r['q_halt'] === 0));

    hr('A. 국면(연도)별로 일관된가 — 이 표가 이번 작업의 답이다');
    say(sprintf('  %-6s %7s %10s %10s %10s %10s %10s', '연도', 'n',
        '익일시가', '익일종가', 'D+5', 'D+20', 'D+5 양(+)'));
    $yrs = array_values(array_unique(array_map(fn($r) => (int)$r['yr'], $clean)));
    sort($yrs);
    foreach ($yrs as $y) {
        $g = array_values(array_filter($clean, fn($r) => (int)$r['yr'] === $y));
        $o = qm_stat($col($g, 'f_nd_open_ret'));  $c1 = qm_stat($col($g, 'f_nd_close_ret'));
        $d5 = qm_stat($col($g, 'f_d5_ret'));      $d20 = qm_stat($col($g, 'f_d20_ret'));
        say(sprintf('  %-6d %7s %9.2f%% %9.2f%% %9.2f%% %9.2f%% %9.1f%%', $y, number_format(count($g)),
            $o['mean'] ?? 0, $c1['mean'] ?? 0, $d5['mean'] ?? 0, $d20['mean'] ?? 0, $d5['win'] ?? 0));
    }
    $neg = 0;
    foreach ($yrs as $y) {
        $g = array_filter($clean, fn($r) => (int)$r['yr'] === $y);
        $s = qm_stat($col(array_values($g), 'f_d5_ret'));
        if (($s['mean'] ?? 0) < 0) $neg++;
    }
    say('');
    say(sprintf('  ⇒ D+5 평균이 음(−)인 해: <b>%d / %d년</b>', $neg, count($yrs)));

    hr('B. 전체 분포 + 생존편향 민감도');
    $sets = [
        '전체(분할·거래정지 제외)' => $clean,
        '  ↳ 상장폐지 종목까지 빼면' => array_values(array_filter($clean, fn($r) => (int)$r['q_noname'] === 0)),
        '  ↳ 상장폐지 종목만'       => array_values(array_filter($clean, fn($r) => (int)$r['q_noname'] === 1)),
    ];
    foreach ($sets as $nm => $rs) {
        say('  [' . $nm . '] n=' . number_format(count($rs)));
        foreach (['f_nd_open_ret' => '익일 시가', 'f_nd_close_ret' => '익일 종가',
                  'f_d5_ret' => 'D+5 종가', 'f_d20_ret' => 'D+20 종가',
                  'f_post_mdd' => '사후 최대낙폭', 'f_post_mfe' => '사후 최대상승'] as $c => $lab) {
            qm_line($lab, qm_stat($col($rs, $c)));
        }
    }

    hr('C. 가설1 재검정 — 상단 마감이면 익일 시가가 좋은가 (분봉 표본에서 t=2.26 이었다)');
    say('  기준 f_close_vs_high ≥ −3% · 결과 f_nd_open_ret');
    say(sprintf('  %-6s %8s %8s %10s %8s', '연도', 'n(상단)', 'n(그밖)', '차이(%p)', 'Welch t'));
    foreach ($yrs as $y) {
        $g  = array_filter($clean, fn($r) => (int)$r['yr'] === $y && $r['f_close_vs_high'] !== null);
        $hi = array_values(array_filter($g, fn($r) => (float)$r['f_close_vs_high'] >= -3));
        $lo = array_values(array_filter($g, fn($r) => (float)$r['f_close_vs_high'] <  -3));
        $a = qm_stat($col($hi, 'f_nd_open_ret')); $b = qm_stat($col($lo, 'f_nd_open_ret'));
        $t = qm_welch($a, $b);
        say(sprintf('  %-6d %8s %8s %+9.2f %8s', $y, number_format($a['n'] ?? 0),
            number_format($b['n'] ?? 0), ($a['mean'] ?? 0) - ($b['mean'] ?? 0),
            $t === null ? '-' : sprintf('%.2f', $t)));
    }
    $hi = array_values(array_filter($clean, fn($r) => $r['f_close_vs_high'] !== null && (float)$r['f_close_vs_high'] >= -3));
    $lo = array_values(array_filter($clean, fn($r) => $r['f_close_vs_high'] !== null && (float)$r['f_close_vs_high'] <  -3));
    $a = qm_stat($col($hi, 'f_nd_open_ret')); $b = qm_stat($col($lo, 'f_nd_open_ret'));
    $t = qm_welch($a, $b);
    say(sprintf('  %-6s %8s %8s %+9.2f %8.2f  ← 8년 전체', '전체', number_format($a['n']),
        number_format($b['n']), $a['mean'] - $b['mean'], $t));

    hr('D. 가설3 재검정 — 사전 거래량 배수별 D+5');
    $ends = [];
    foreach ([['~2배', 0, 2], ['2~5배', 2, 5], ['5~10배', 5, 10], ['10~20배', 10, 20], ['20배~', 20, 1e9]] as [$bn, $x0, $x1]) {
        $g = array_values(array_filter($clean, fn($r) => $r['f_pre_vol_mult'] !== null
             && (float)$r['f_pre_vol_mult'] >= $x0 && (float)$r['f_pre_vol_mult'] < $x1));
        $s = qm_stat($col($g, 'f_d5_ret'));
        qm_line($bn, $s);
        $ends[] = $s;
    }
    $t = qm_welch($ends[0], end($ends));
    if ($t !== null) say(sprintf('    양 끝 차이 %+.2f%%p · Welch t=%.2f%s',
        $ends[0]['mean'] - end($ends)['mean'], $t, abs($t) > 2 ? '' : '  (|t|≤2)'));

    hr('플래그');
    foreach (['q_split' => '분할 낌', 'q_halt' => '거래 없는 날', 'q_noname' => '상장폐지(이름없음)'] as $c => $nm) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM qm_dday WHERE {$c}=1")->fetchColumn();
        say(sprintf('  %-20s %6s건', $nm, number_format($n)));
    }
    say('');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    say('  ⛔일봉으론 못 보는 것: 도달 «시각» · 첫 30분 몰림 · VWAP · 재돌파. 그건 분봉만 답한다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dbofill — 「신고가 돌파」 진입 조건을 qm_dday 에 채운다. API 콜 0 · 멱등
//
//  묻는 것: 「거래량 N일 평균의 x배 + 등락률 10%↑ + 종가가 최근 60/120일 고가 돌파」
//           를 진입으로 잡으면 어떤가.
//  ★등락률 10%↑ 와 거래대금 100억↑ 은 qm_dday 가 «이미» 그 표본이다 — 여기서 더하지 않는다.
//
//  ★★자료가 모자라면 0 이 아니라 NULL 이다. 상장 80일짜리 종목에 120일 돌파를 0/1 로 적으면
//    「돌파 못 했다」로 세어져 비돌파 군이 신규상장으로 오염된다(§9 가 금지한 조용한 왜곡).
// ══════════════════════════════════════════════════════════════════════════
case 'dbofill': {
    foreach ([
        'f_hi60_brk'   => "TINYINT NULL COMMENT '종가가 직전 60거래일 최고가 초과=1 · 자료부족 NULL'",
        'f_hi120_brk'  => "TINYINT NULL COMMENT '종가가 직전 120거래일 최고가 초과=1 · 자료부족 NULL'",
        'f_hi60_over'  => "DECIMAL(6,2) NULL COMMENT '종가/직전 60일 최고가-1 (%)'",
        'f_hi120_over' => "DECIMAL(6,2) NULL COMMENT '종가/직전 120일 최고가-1 (%)'",
        'f_vol_mult20' => "DECIMAL(8,3) NULL COMMENT '거래량/직전 20일 평균'",
        'f_vol_mult60' => "DECIMAL(8,3) NULL COMMENT '거래량/직전 60일 평균'",
    ] as $c => $def) {
        try { $pdo->exec("ALTER TABLE qm_dday ADD COLUMN IF NOT EXISTS {$c} {$def}"); }
        catch (Throwable $e) { say('  (컬럼 ' . $c . ' 추가 실패: ' . $e->getMessage() . ')'); }
    }

    $codes = $pdo->query("SELECT DISTINCT code FROM qm_dday ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
    say('신고가 돌파 조건 계산 — 종목 ' . number_format(count($codes)) . '개 · API 콜 0');
    say('★자료가 60(120)거래일에 못 미치면 NULL 로 둔다 — 0 으로 적으면 비돌파 군이 오염된다.');

    /* dbuild 와 «같은 계열»을 봐야 한다 — 거기도 c>0 로 걸러 훑었다.
     * 다르게 걸면 「직전 60거래일」이 두 잡에서 다른 날을 가리킨다. */
    $sel = $pdo->prepare("SELECT d,h,vol FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $tgt = $pdo->prepare("SELECT d, close_prc FROM qm_dday WHERE code=?");
    $upd = $pdo->prepare("UPDATE qm_dday SET f_hi60_brk=?, f_hi120_brk=?, f_hi60_over=?,
                                 f_hi120_over=?, f_vol_mult20=?, f_vol_mult60=?
                           WHERE code=? AND d=?");

    $nRow = 0; $n60 = 0; $n120 = 0; $nNull120 = 0;
    $nUnk = 0;              // 결측이 껴 «돌파인지 모른다»로 비운 판정
    $pdo->beginTransaction();
    foreach ($codes as $ci => $code) {
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$s) continue;
        $idx = [];
        foreach ($s as $i => $r) $idx[$r['d']] = $i;

        $tgt->execute([$code]);
        foreach ($tgt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $i = $idx[$t['d']] ?? null;
            if ($i === null) continue;                      // 원장에서 사라진 날 — 건드리지 않는다
            $cc = (float)$t['close_prc'];

            /* 직전 W거래일의 «고가» 최고 — 오늘은 뺀다.
             * ★오늘을 넣으면 종가 ≤ 당일고가 라 돌파가 영영 성립하지 않는다
             *   (분봉 「직전고가」 규칙과 같은 이유 — CLAUDE.md 차트 절).
             * ★★창 안에 «진짜 결측»(거래는 있었는데 고가를 모르는 날)이 있으면 최고가는
             *   «하한»일 뿐이다 — 실제 최고는 그보다 높을 수 있다. 그래서 두 번째 값으로
             *   그 사실을 함께 돌려준다. 거래정지일은 고가가 «없는 게 사실»이라 그냥 지나간다. */
            $peak = function (int $w) use ($s, $i) {
                if ($i < $w) return [null, false];          // 자료 부족 — 0 이 아니라 없음
                $m = 0.0; $lower = false;
                for ($k = $i - $w; $k < $i; $k++) {
                    if (qm_day_kind($s[$k]) === 'gap') { $lower = true; continue; }
                    $m = max($m, (float)$s[$k]['h']);
                }
                return [$m > 0 ? $m : null, $lower];
            };
            $vmult = function (int $w) use ($s, $i) {
                if ($i < $w) return null;
                $sum = 0.0;
                for ($k = $i - $w; $k < $i; $k++) $sum += (float)$s[$k]['vol'];
                $avg = $sum / $w;
                return $avg > 0 ? round((float)$s[$i]['vol'] / $avg, 3) : null;
            };

            /* ★결측이 낀 창은 «비돌파만» 확정할 수 있다 — 못 본 날의 고가는 최고를 올릴 수만 있으니
             *   종가가 이미 그 하한에 못 미치면 결측이 어떻든 비돌파다. 반대로 넘었다면 «모른다»(NULL).
             *   초과폭(%)은 최고가 자체가 하한이라 어느 쪽이든 적을 수 없다. */
            $judge = function (array $pk) use ($cc, &$nUnk) {
                [$p, $lower] = $pk;
                if ($p === null) return [null, null];
                if (!$lower)     return [$cc > $p ? 1 : 0, round(($cc / $p - 1) * 100, 2)];
                if ($cc > $p)    { $nUnk++; return [null, null]; }
                return [0, null];
            };
            [$b60,  $o60]  = $judge($peak(60));
            [$b120, $o120] = $judge($peak(120));
            $upd->execute([$b60, $b120, $o60, $o120, $vmult(20), $vmult(60), $code, $t['d']]);
            $nRow++;
            if ($b60 === 1)     $n60++;
            if ($b120 === 1)    $n120++;
            if ($b120 === null) $nNull120++;
        }
        if ($ci % 300 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
    }
    $pdo->commit();

    say('');
    say(sprintf('  채운 행 %s · 60일 돌파 %s (%.1f%%) · 120일 돌파 %s (%.1f%%)',
        number_format($nRow), number_format($n60), $nRow ? $n60 / $nRow * 100 : 0,
        number_format($n120), $nRow ? $n120 / $nRow * 100 : 0));
    say(sprintf('  120일 자료부족(NULL) %s건 — 상장 120거래일 미만이라 «판정 안 함»',
        number_format($nNull120)));
    say(sprintf('  ★결측이 껴 «돌파인지 모른다»로 비운 판정 %s건 — 못 본 날의 고가는 최고를 올릴 수만'
        . ' 있어 「비돌파」는 확정할 수 있지만 「돌파」는 확정할 수 없다', number_format($nUnk)));
    say('  ※거래정지일(vol=0)은 «고가가 없는 것이 사실»이라 그냥 지나간다 — 결측과 다르다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dbo — 신고가 돌파 진입의 일봉 검정. qm_dday 만 읽는다 · API 콜 0
//
//  ⛔규율은 danalyze 와 같다(§9). 여기에 더해 <b>오늘 배운 것</b>을 반드시 지킨다 —
//    ★★「상한가 분해」를 빼놓지 않는다. 08-06 실측: 「상단마감 우위」의 정체가 상한가였고
//      그 절반은 종가에 «살 수 없었다». 종가 진입을 재는 표는 전부 같은 함정 위에 있다.
// ══════════════════════════════════════════════════════════════════════════
case 'dbo': {
    $rows = $pdo->query("SELECT * FROM qm_dday")->fetchAll(PDO::FETCH_ASSOC);
    $col  = fn(array $rs, string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $rs);
    $num  = fn($v) => number_format((int)$v);

    say('신고가 돌파 진입 검정 — 8년 일봉  (' . date('Y-m-d H:i') . ')');
    say('진입 정의: 등락률 +' . QM_CHG_MIN . '%↑ · 거래대금 '
        . number_format(QM_AMT_MIN / 1e8) . '억↑ · 거래량 배수 · 종가가 직전 60(120)일 고가 초과');
    say('진입 시점 = 그 날 «종가» · 자료 `krx_amt` 2019-01-02~ · API 콜 0');
    say('제외: 우선주·스팩·ETF·ETN·분할일·상장 60거래일 미만 / ★상장폐지는 «빼지 않는다»(생존편향)');

    if (!array_key_exists('f_hi60_brk', $rows[0] ?? [])) {
        say('★컬럼이 없다 — 먼저 job=dbofill 을 돌린다.');
        break;
    }

    /* 분할·거래정지가 낀 건은 수익률 자체가 거짓이라 그것만 뺀다(danalyze 와 같은 기준). */
    $clean = array_values(array_filter($rows,
        fn($r) => (int)$r['q_split'] === 0 && (int)$r['q_halt'] === 0));
    $isLim = fn(array $r) => (float)$r['chg_pct'] >= 29.0;   // 상한가 판정 — gap 절과 같은 자
    $has   = fn(array $r, string $c) => $r[$c] !== null;

    hr('B0. 표본 — 조건을 하나씩 걸면 몇 건이 남나');
    say(sprintf('  %-34s %8s', '전체 급등 이벤트(10%↑·100억↑)', $num(count($rows))));
    say(sprintf('  %-34s %8s', '  분할·거래정지 뺀 것', $num(count($clean))));
    foreach ([60, 120] as $w) {
        $k  = "f_hi{$w}_brk";
        $ok = array_values(array_filter($clean, fn($r) => $has($r, $k)));
        $br = array_values(array_filter($ok, fn($r) => (int)$r[$k] === 1));
        $lm = count(array_filter($br, $isLim));
        say(sprintf('  %-34s %8s  (판정가능 %s 중 %.1f%%) · 그중 상한가 %s (%.1f%%)',
            "  {$w}일 고가 돌파", $num(count($br)), $num(count($ok)),
            count($ok) ? count($br) / count($ok) * 100 : 0, $num($lm),
            count($br) ? $lm / count($br) * 100 : 0));
    }
    say('  ※ 「판정가능」이 전체보다 적은 것은 상장 120거래일 미만을 NULL 로 두기 때문이다.');

    /* 결과변수는 여섯을 나란히 본다 — 하나만 보면 「어디서 벌고 어디서 잃나」가 안 보인다. */
    $OUT = ['익일시가' => 'f_nd_open_ret', '익일종가' => 'f_nd_close_ret',
            'D+5' => 'f_d5_ret', 'D+20' => 'f_d20_ret',
            '사후 최대상승' => 'f_post_mfe', '사후 최대낙폭' => 'f_post_mdd'];

    $table = function (array $g, string $label) use ($OUT, $col, $num) {
        say(sprintf('    %-14s n=%6s', $label, $num(count($g))));
        foreach ($OUT as $nm => $c) {
            $s = qm_stat($col($g, $c));
            if (!$s['n']) { say(sprintf('      %-14s n=0', $nm)); continue; }
            say(sprintf('      %-14s n=%6d  평균 %7.2f%%  중앙 %7.2f%%  양(+) %5.1f%%%s',
                $nm, $s['n'], $s['mean'], $s['med'], $s['win'],
                $s['n'] < 30 ? '  ←n 30 미만 결론 보류' : ''));
        }
    };
    /* 두 군의 «같은» 결과변수를 견준다 — 차이와 t 를 함께 적는다(§9 ②③) */
    $cmp = function (array $a, array $b, string $la, string $lb) use ($OUT, $col) {
        say(sprintf('      %-14s %10s %10s %10s %8s', '', $la, $lb, '차이(%p)', 'Welch t'));
        foreach ($OUT as $nm => $c) {
            $sa = qm_stat($col($a, $c)); $sb = qm_stat($col($b, $c));
            if (!($sa['n'] ?? 0) || !($sb['n'] ?? 0)) continue;
            $t = qm_welch($sa, $sb);
            say(sprintf('      %-14s %9.2f%% %9.2f%% %+9.2f%%p %8s%s', $nm,
                $sa['mean'], $sb['mean'], $sa['mean'] - $sb['mean'],
                $t === null ? '-' : sprintf('%.2f', $t),
                ($t !== null && abs($t) <= 2) ? '  (|t| 2 이하 — 주장 안 함)' : ''));
        }
    };

    foreach ([60, 120] as $w) {
        $k  = "f_hi{$w}_brk";
        $ok = array_values(array_filter($clean, fn($r) => $has($r, $k)));
        $br = array_values(array_filter($ok, fn($r) => (int)$r[$k] === 1));
        $nb = array_values(array_filter($ok, fn($r) => (int)$r[$k] === 0));

        hr("B1-{$w}. {$w}일 고가 돌파 vs 비돌파  (n=" . number_format(count($ok)) . ')');
        $cmp($br, $nb, '돌파', '비돌파');

        /* ★★상한가 분해 — 오늘의 교훈. 종가 진입은 상한가에서 «값이 있어도 못 산다».
         *   빼고도 남는지가 그 조건이 진짜인지를 가른다. */
        $brN = array_values(array_filter($br, fn($r) => !$isLim($r)));
        $nbN = array_values(array_filter($nb, fn($r) => !$isLim($r)));
        say('');
        say('    ★상한가(등락률 29%↑)를 뺀 뒤 — 종가에 실제로 살 수 있는 건만');
        $cmp($brN, $nbN, '돌파', '비돌파');
    }

    /* 거래량 배수는 «사용자가 정할 값»이라 구간을 나눠 보여 준다.
     * 20일 기준과 60일 기준을 둘 다 — 기준일이 길수록 배수가 커지므로 같은 「3배」가 다른 뜻이다. */
    foreach ([20, 60] as $vb) {
        $vk = "f_vol_mult{$vb}";
        hr("B2-{$vb}. 60일 돌파 «안에서» 거래량 배수별 (기준 직전 {$vb}일 평균)");
        $base = array_values(array_filter($clean,
            fn($r) => $has($r, 'f_hi60_brk') && (int)$r['f_hi60_brk'] === 1 && $has($r, $vk)));
        say('    대상 n=' . number_format(count($base)) . ' (60일 돌파 · 배수 계산 가능)');
        foreach ([[0, 2, '2배 미만'], [2, 3, '2~3배'], [3, 5, '3~5배'], [5, 10, '5~10배'],
                  [10, 1e9, '10배 이상']] as [$lo, $hi, $lab]) {
            $g = array_values(array_filter($base,
                fn($r) => (float)$r[$vk] >= $lo && (float)$r[$vk] < $hi));
            $d5 = qm_stat($col($g, 'f_d5_ret')); $o = qm_stat($col($g, 'f_nd_open_ret'));
            $mfe = qm_stat($col($g, 'f_post_mfe')); $mdd = qm_stat($col($g, 'f_post_mdd'));
            $lim = count($g) ? count(array_filter($g, $isLim)) / count($g) * 100 : 0;
            say(sprintf('      %-10s n=%6s  익일시가 %6.2f%%  D+5 %6.2f%% (양 %4.1f%%)  '
                . '최대상승 %6.2f%%  최대낙폭 %6.2f%%  상한가 %4.1f%%%s',
                $lab, number_format(count($g)), $o['mean'] ?? 0, $d5['mean'] ?? 0, $d5['win'] ?? 0,
                $mfe['mean'] ?? 0, $mdd['mean'] ?? 0, $lim,
                count($g) < 30 ? '  ←n 30 미만' : ''));
        }
        /* ★구간 표에도 t 를 적는다 — 이 파일의 다른 표가 전부 그렇게 한다(§9 ②③).
         *   없으면 「10배 이상이 나쁘다」를 눈대중으로 말하게 된다.
         *   ⊕상한가를 뺀 값을 «함께» 낸다 — 10배 이상 구간은 상한가 비중이 배로 높아서
         *     그것만으로도 구간 차이가 생길 수 있다(오늘 세 번째로 만난 함정). */
        $bk = fn(float $lo, float $hi, bool $exLim) => array_values(array_filter($base,
            fn($r) => (float)$r[$vk] >= $lo && (float)$r[$vk] < $hi && (!$exLim || !$isLim($r))));
        foreach ([false, true] as $exLim) {
            $lowS = qm_stat($col($bk(2, 10, $exLim), 'f_d5_ret'));
            $hiS  = qm_stat($col($bk(10, 1e9, $exLim), 'f_d5_ret'));
            $t    = qm_welch($lowS, $hiS);
            say(sprintf('      → D+5 «2~10배» %.2f%% (n=%s) vs «10배 이상» %.2f%% (n=%s) '
                . '차이 %+.2f%%p · Welch t=%s%s   [%s]',
                $lowS['mean'] ?? 0, number_format($lowS['n'] ?? 0),
                $hiS['mean'] ?? 0, number_format($hiS['n'] ?? 0),
                ($lowS['mean'] ?? 0) - ($hiS['mean'] ?? 0),
                $t === null ? '-' : sprintf('%.2f', $t),
                ($t !== null && abs($t) <= 2) ? '  (|t| 2 이하 — 주장 안 함)' : '',
                $exLim ? '상한가 제외' : '있는 그대로'));
        }
    }

    /* ★국면 검증 — 8년 일봉을 쓰는 «유일한» 이유다. 한 해만 좋은 조건은 조건이 아니다. */
    hr('B3. 연도별 일관성 — 60일 돌파 · 상한가 제외 (이 표가 국면 의존성을 가른다)');
    say(sprintf('  %-6s %7s %10s %10s %10s %10s', '연도', 'n', '익일시가', 'D+5', 'D+5 양(+)', '최대상승'));
    $bo = array_values(array_filter($clean, fn($r) => $has($r, 'f_hi60_brk')
        && (int)$r['f_hi60_brk'] === 1 && !$isLim($r)));
    $yrs = array_values(array_unique(array_map(fn($r) => (int)$r['yr'], $bo)));
    sort($yrs);
    $negD5 = 0;
    foreach ($yrs as $y) {
        $g = array_values(array_filter($bo, fn($r) => (int)$r['yr'] === $y));
        $o = qm_stat($col($g, 'f_nd_open_ret')); $d5 = qm_stat($col($g, 'f_d5_ret'));
        $mfe = qm_stat($col($g, 'f_post_mfe'));
        if (($d5['mean'] ?? 0) < 0) $negD5++;
        say(sprintf('  %-6d %7s %9.2f%% %9.2f%% %9.1f%% %9.2f%%', $y, number_format(count($g)),
            $o['mean'] ?? 0, $d5['mean'] ?? 0, $d5['win'] ?? 0, $mfe['mean'] ?? 0));
    }
    say(sprintf('  ★D+5 평균이 음(−)인 해: %d / %d', $negD5, count($yrs)));

    hr('B4. 플래그 — 빼지 않고 «몇 건인지» 적는다');
    foreach ([['분할 낀 건', fn($r) => (int)$r['q_split'] === 1],
              ['거래정지 낀 건', fn($r) => (int)$r['q_halt'] === 1],
              ['이름 없음(대개 상장폐지)', fn($r) => (int)$r['q_noname'] === 1]] as [$nm, $f]) {
        say(sprintf('  %-24s %8s건', $nm, number_format(count(array_filter($rows, $f)))));
    }
    say('');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    say('  ⛔종가 진입의 «체결 가능성»은 일봉으로 못 잰다 — 상한가 비율을 함께 읽는다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dmfefill — 「최대상승이 «언제» 오는가」를 채운다. API 콜 0 · 멱등
//
//  진입은 정해진 것으로 둔다(급등일 종가 매수). 여기서 묻는 것은 <b>청산</b>뿐이다.
//
//  ★★n_post=5 인 건만 채운다. 사후창이 2일뿐인 건에 「며칠째」를 적으면
//    최대가 1~2일에만 있을 수 있어 <b>분포가 앞으로 쏠린다</b> — 아무 표시 없이 거짓이 된다.
//  ★★최고와 최저가 «같은 날»이면 어느 쪽이 먼저인지 일봉으론 모른다 → 2(판정불가)로 적는다.
//    0/1 중 하나로 몰면 「기다리다 손절 먼저 맞나」의 답이 조용히 기운다.
// ══════════════════════════════════════════════════════════════════════════
case 'dmfefill': {
    /* 목표가 청산 시뮬의 목표값(%) — 바꾸려면 여기 한 줄. 컬럼명이 값을 담으므로 함께 고친다. */
    $TGT = [3 => 'f_exit3', 5 => 'f_exit5', 7 => 'f_exit7', 10 => 'f_exit10', 15 => 'f_exit15'];

    $cols = [
        'f_mfe_day'       => "TINYINT NULL COMMENT '사후 최대상승이 D+며칠째 (1~5) · n_post=5 만'",
        'f_mdd_day'       => "TINYINT NULL COMMENT '사후 최대낙폭이 D+며칠째 (1~5)'",
        'f_mfe_day_close' => "DECIMAL(6,2) NULL COMMENT '최대상승일의 «종가»/D0종가-1 — 고가에 못 팔았을 때'",
        'f_mfe_order'     => "TINYINT NULL COMMENT '1=상승이 먼저 · 0=낙폭이 먼저 · 2=같은 날(일봉으론 판정불가)'",
    ];
    foreach ($TGT as $x => $c) $cols[$c] = "DECIMAL(6,2) NULL COMMENT '+{$x}% 목표가 청산 시뮬 수익률'";
    foreach ($cols as $c => $def) {
        try { $pdo->exec("ALTER TABLE qm_dday ADD COLUMN IF NOT EXISTS {$c} {$def}"); }
        catch (Throwable $e) { say('  (컬럼 ' . $c . ' 추가 실패: ' . $e->getMessage() . ')'); }
    }

    $codes = $pdo->query("SELECT DISTINCT code FROM qm_dday WHERE n_post=5 ORDER BY code")
                 ->fetchAll(PDO::FETCH_COLUMN);
    say('사후 최대상승 시점 계산 — 종목 ' . number_format(count($codes)) . '개 · API 콜 0');
    say('★사후창이 5거래일 «다 찬» 건만 채운다 — 덜 찬 건에 「며칠째」를 적으면 분포가 앞으로 쏠린다.');
    say('★목표가 청산 시뮬: ' . implode('·', array_map(fn($x) => '+' . $x . '%', array_keys($TGT))));

    say('★거래정지일은 «건너뛴다» — 고·저가 없는 날이라 0 으로 읽으면 최저가 −100%% 가 된다(qm_day_kind).');

    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $tgt = $pdo->prepare("SELECT d, close_prc FROM qm_dday WHERE code=? AND n_post=5");
    $set = implode(',', array_map(fn($c) => "{$c}=?",
        array_merge(['f_mfe_day', 'f_mdd_day', 'f_mfe_day_close', 'f_mfe_order'], array_values($TGT))));
    $upd = $pdo->prepare("UPDATE qm_dday SET {$set} WHERE code=? AND d=?");

    $nRow = 0; $nSame = 0; $nNoDay = 0;
    $pdo->beginTransaction();
    foreach ($codes as $ci => $code) {
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$s) continue;
        $idx = [];
        foreach ($s as $i => $r) $idx[$r['d']] = $i;

        $tgt->execute([$code]);
        foreach ($tgt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $i = $idx[$t['d']] ?? null;
            if ($i === null || !isset($s[$i + 5])) continue;
            $c0 = (float)$t['close_prc'];
            if ($c0 <= 0) continue;

            /* ★거래정지·결측일은 고·저가 «없다» — 세지 않고 넘어간다.
             *   0 으로 읽으면 그 날이 언제나 최저가 되어 「최대낙폭이 D+며칠째」가 통째로 거짓이 된다. */
            $mfe = null; $mfeDay = 0; $mdd = null; $mddDay = 0; $lastOkJ = 0;
            for ($j = 1; $j <= 5; $j++) {
                $p = $s[$i + $j];
                if (qm_day_kind($p) !== 'ok') continue;
                $lastOkJ = $j;
                $h = (float)$p['h']; $l = (float)$p['l'];
                if ($mfe === null || $h > $mfe) { $mfe = $h; $mfeDay = $j; }
                if ($mdd === null || $l < $mdd) { $mdd = $l; $mddDay = $j; }
            }
            if ($mfeDay === 0 || $mddDay === 0) { $nNoDay++; continue; }   // 창 안에 거래일이 없다
            /* ★같은 날이면 «모른다» — 일봉은 그 날 안의 순서를 담지 않는다 */
            $order = $mfeDay === $mddDay ? 2 : ($mfeDay < $mddDay ? 1 : 0);
            if ($order === 2) $nSame++;

            /* 목표가 청산 시뮬 — 규칙: D+1 부터 훑어
             *   ①그 날 «시가»가 이미 목표 위면 시가에 판다(갭으로 넘겨 시작 — 실제로 더 좋다)
             *   ②아니고 «고가»가 목표에 닿으면 목표가에 판다
             *   ③끝까지 안 닿으면 D+5 «종가»에 판다
             * ⛔①②는 «닿았다»를 «팔았다»로 본다 — 호가·체결은 일봉에 없다. 낙관 편향이다. */
            $ex = [];
            foreach ($TGT as $x => $cName) {
                $ret = null;
                for ($j = 1; $j <= 5; $j++) {
                    $p = $s[$i + $j];
                    if (qm_day_kind($p) !== 'ok') continue;      // 그 날은 못 판다
                    $op = ((float)$p['o'] / $c0 - 1) * 100;
                    $hp = ((float)$p['h'] / $c0 - 1) * 100;
                    if ($op >= $x) { $ret = $op; break; }
                    if ($hp >= $x) { $ret = (float)$x; break; }
                }
                /* ③끝까지 안 닿으면 «마지막 거래 가능일» 종가에 판다 — D+5 가 정지면 이월된 값이다 */
                if ($ret === null) $ret = ((float)$s[$i + $lastOkJ]['c'] / $c0 - 1) * 100;
                $ex[] = round($ret, 2);
            }

            $upd->execute(array_merge([
                $mfeDay, $mddDay,
                round(((float)$s[$i + $mfeDay]['c'] / $c0 - 1) * 100, 2),
                $order,
            ], $ex, [$code, $t['d']]));
            $nRow++;
        }
        if ($ci % 300 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
    }
    $pdo->commit();

    say('');
    say(sprintf('  채운 행 %s · 최고와 최저가 «같은 날» %s건 (%.1f%% — 순서 판정불가)',
        number_format($nRow), number_format($nSame), $nRow ? $nSame / $nRow * 100 : 0));
    if ($nNoDay) say('  ★사후 5일이 통째로 거래정지·결측이라 «안 채운» 행 ' . number_format($nNoDay) . '건');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dmfe — 「언제 파나」의 일봉 검정. qm_dday 만 읽는다 · API 콜 0
// ══════════════════════════════════════════════════════════════════════════
case 'dmfe': {
    $rows = $pdo->query("SELECT * FROM qm_dday WHERE n_post=5 AND f_mfe_day IS NOT NULL")
                ->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { say('★자료가 없다 — 먼저 job=dmfefill 을 돌린다.'); break; }
    $col = fn(array $rs, string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $rs);
    $isLim = fn(array $r) => (float)$r['chg_pct'] >= 29.0;

    say('사후 최대상승은 «언제» 오는가 — 8년 일봉  (' . date('Y-m-d H:i') . ')');
    say('진입은 정해진 것으로 둔다 — 급등일(+' . QM_CHG_MIN . '%↑·100억↑) «종가» 매수. 묻는 것은 청산뿐이다.');
    say('★사후창이 5거래일 다 찬 건만 — 덜 찬 건은 「며칠째」가 앞으로 쏠린다.');
    say('⛔최대상승은 «고가»의 최고치다 — 사후에만 아는 «위쪽 한계»이고 그 값에 팔 수 있다는 뜻이 아니다.');

    $clean = array_values(array_filter($rows,
        fn($r) => (int)$r['q_split'] === 0 && (int)$r['q_halt'] === 0));
    $noLim = array_values(array_filter($clean, fn($r) => !$isLim($r)));

    /* 며칠째 분포를 한 줄로 — n 과 비율을 «항상» 함께 적는다(§9 ②) */
    $dist = function (array $g, string $label, string $key) {
        $n = count($g);
        if (!$n) { say(sprintf('    %-26s n=0', $label)); return; }
        $c = array_fill(1, 5, 0);
        foreach ($g as $r) { $d = (int)$r[$key]; if ($d >= 1 && $d <= 5) $c[$d]++; }
        $s = '';
        for ($d = 1; $d <= 5; $d++) $s .= sprintf(' D+%d %5.1f%%', $d, $c[$d] / $n * 100);
        say(sprintf('    %-26s n=%6s %s', $label, number_format($n), $s));
    };

    hr('C1. 최대상승이 며칠째 오는가 — 이 표가 보유 기간을 정한다');
    $dist($clean, '전체', 'f_mfe_day');
    $dist($noLim, '상한가 제외', 'f_mfe_day');
    say('');
    say('  같은 자로 잰 최대낙폭의 날 — 견줄 대상이 있어야 «이르다/늦다»를 말할 수 있다');
    $dist($clean, '전체 (최대낙폭)', 'f_mdd_day');
    $dist($noLim, '상한가 제외 (최대낙폭)', 'f_mdd_day');

    hr('C2. 그룹이 타이밍을 바꾸는가 — 바꾼다면 그건 «청산» 근거다');
    foreach ([
        ['60일 돌파', fn($r) => $r['f_hi60_brk'] !== null && (int)$r['f_hi60_brk'] === 1],
        ['60일 비돌파', fn($r) => $r['f_hi60_brk'] !== null && (int)$r['f_hi60_brk'] === 0],
        ['거래량 10배 이상', fn($r) => $r['f_vol_mult60'] !== null && (float)$r['f_vol_mult60'] >= 10],
        ['거래량 2~10배', fn($r) => $r['f_vol_mult60'] !== null
            && (float)$r['f_vol_mult60'] >= 2 && (float)$r['f_vol_mult60'] < 10],
        ['상한가 마감', $isLim],
    ] as [$nm, $f]) $dist(array_values(array_filter($clean, $f)), $nm, 'f_mfe_day');

    hr('C3. ★순서 — 최대상승이 먼저인가, 최대낙폭이 먼저인가');
    say('  이 표가 나쁘면 「최고점을 기다린다」는 말 자체가 성립하지 않는다 — 먼저 밀리기 때문이다.');
    foreach ([['전체', $clean], ['상한가 제외', $noLim]] as [$nm, $g]) {
        $n = count($g);
        $a = count(array_filter($g, fn($r) => (int)$r['f_mfe_order'] === 1));
        $b = count(array_filter($g, fn($r) => (int)$r['f_mfe_order'] === 0));
        $c = count(array_filter($g, fn($r) => (int)$r['f_mfe_order'] === 2));
        say(sprintf('    %-14s n=%6s   상승 먼저 %5.1f%%   낙폭 먼저 %5.1f%%   같은 날 %5.1f%%(판정불가)',
            $nm, number_format($n), $n ? $a / $n * 100 : 0, $n ? $b / $n * 100 : 0, $n ? $c / $n * 100 : 0));
    }

    hr('C4. 고가에 못 팔면 얼마가 남나 — 같은 건을 세 가지로 잰 값');
    foreach ([['전체', $clean], ['상한가 제외', $noLim]] as [$nm, $g]) {
        say('    [' . $nm . '] n=' . number_format(count($g)));
        foreach (['최대상승(고가·위쪽 한계)' => 'f_post_mfe',
                  '그 날 종가에 팔았다면' => 'f_mfe_day_close',
                  'D+5 종가까지 들었다면' => 'f_d5_ret',
                  '최대낙폭(아래쪽 한계)' => 'f_post_mdd'] as $lab => $c) {
            $s = qm_stat($col($g, $c));
            if (!$s['n']) continue;
            say(sprintf('      %-24s 평균 %7.2f%%  중앙 %7.2f%%  양(+) %5.1f%%',
                $lab, $s['mean'], $s['med'], $s['win']));
        }
    }

    hr('C5. 목표가 청산 시뮬 — ⛔「닿았다」를 「팔았다」로 본다(낙관 편향) · 비용 미반영');
    say('  규칙: D+1~D+5 중 시가가 목표 위면 시가에, 고가가 목표에 닿으면 목표가에, 끝내 안 닿으면 D+5 종가에.');
    foreach ([['전체', $clean], ['상한가 제외', $noLim]] as [$nm, $g]) {
        say('    [' . $nm . '] n=' . number_format(count($g)));
        $base = qm_stat($col($g, 'f_d5_ret'));
        say(sprintf('      %-14s 평균 %7.2f%%  중앙 %7.2f%%  양(+) %5.1f%%   ← 견줄 기준',
            'D+5 종가 보유', $base['mean'], $base['med'], $base['win']));
        foreach ([3 => 'f_exit3', 5 => 'f_exit5', 7 => 'f_exit7', 10 => 'f_exit10', 15 => 'f_exit15']
                 as $x => $c) {
            $s = qm_stat($col($g, $c));
            if (!$s['n']) continue;
            /* 도달률 — 목표에 닿아 «중간에» 팔린 비율. 시뮬 값이 목표 이상이면 닿은 것이다. */
            $hit = count(array_filter($g, fn($r) => $r[$c] !== null && (float)$r[$c] >= $x)) / max(1, count($g)) * 100;
            $t = qm_welch($s, $base);
            say(sprintf('      +%-2d%% 목표      평균 %7.2f%%  중앙 %7.2f%%  양(+) %5.1f%%  도달 %5.1f%%  '
                . 'vs 보유 %+.2f%%p · t=%s%s', $x, $s['mean'], $s['med'], $s['win'], $hit,
                $s['mean'] - $base['mean'], $t === null ? '-' : sprintf('%.2f', $t),
                ($t !== null && abs($t) <= 2) ? '  (|t| 2 이하)' : ''));
        }
    }

    hr('C6. 연도별 일관성 — 상한가 제외 · 목표가 청산이 보유를 이기는가');
    say(sprintf('  %-6s %7s %10s %10s %10s %10s', '연도', 'n', 'D+5 보유', '+5% 청산', '+10% 청산', '최대상승일'));
    $yrs = array_values(array_unique(array_map(fn($r) => (int)$r['yr'], $noLim)));
    sort($yrs);
    $win5 = 0;
    foreach ($yrs as $y) {
        $g = array_values(array_filter($noLim, fn($r) => (int)$r['yr'] === $y));
        $b = qm_stat($col($g, 'f_d5_ret')); $e5 = qm_stat($col($g, 'f_exit5'));
        $e10 = qm_stat($col($g, 'f_exit10'));
        $md = qm_stat($col($g, 'f_mfe_day'));
        if (($e5['mean'] ?? 0) > ($b['mean'] ?? 0)) $win5++;
        say(sprintf('  %-6d %7s %9.2f%% %9.2f%% %9.2f%% %9.2f일', $y, number_format(count($g)),
            $b['mean'] ?? 0, $e5['mean'] ?? 0, $e10['mean'] ?? 0, $md['mean'] ?? 0));
    }
    say(sprintf('  ★+5%% 청산이 보유를 이긴 해: %d / %d', $win5, count($yrs)));

    say('');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    say('  ⛔일봉으론 못 보는 것: 그 날 «몇 시»인가 · 그 값에 체결이 있었나. 그건 분봉만 답한다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dbrk tp=15 sl=10 days=5 — 「불꽃형에 +N% 익절 / −M% 손절을 걸면 승률은?」
//
//  ★★★일봉의 근본 한계를 정면으로 다룬다 — <b>같은 날 고가가 익절선에 닿고 저가가 손절선에도
//    닿으면 어느 쪽이 먼저인지 모른다</b>. 그 건을 「모호」로 «따로 세고» 낙관·비관 두 경계를 낸다.
//    한쪽으로 몰면 승률이 통째로 거짓이 된다(브래킷 백테스트가 늘 부풀려지는 이유가 이것이다).
//
//  ★갭을 먼저 본다 — 시가가 이미 익절선 위면 «그 시가»에 팔리고(더 좋다),
//    시가가 손절선 아래면 «그 시가»에 팔린다(더 나쁘다). 브래킷은 갭을 못 막는다.
// ══════════════════════════════════════════════════════════════════════════
case 'dbrk': {
    $TP   = (float)($_GET['tp'] ?? 15);
    $SL   = (float)($_GET['sl'] ?? 10);
    $DAYS = max(1, min(20, (int)($_GET['days'] ?? 5)));
    $COST = 0.20;                                    // 왕복 비용 «가정»(%) — 실측이 아니다
    $WIN  = 120; $MULT = 20;
    /* 거래대금 하한 — «억» 단위로 받는다(기본 100억). 신호 정의의 다른 두 조건
     * (직전 119봉 최고 거래대금 · 20평비 20배)과 «독립»이라, 이 값만 올리면 표본이
     * 그대로 부분집합으로 좁아진다 — 그래서 재수집 없이 비교할 수 있다. */
    $MINAMT = max(1, (int)($_GET['minamt'] ?? 100)) * 100000000;

    $etf = qm_etf_codes($pdo);
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info")->fetchAll(PDO::FETCH_ASSOC)
             as $r) $names[$r['stock_code']] = $r['stock_name'];

    say('불꽃형 브래킷 검정 — 8년 일봉  (' . date('Y-m-d H:i') . ')');
    say(sprintf('규칙: 불꽃형 «신호일 종가»에 매수 → %d거래일 안에 +%.1f%% 익절 / −%.1f%% 손절, '
        . '끝내 안 닿으면 D+%d 종가 청산', $DAYS, $TP, $SL, $DAYS));
    say('신호: 직전 ' . ($WIN - 1) . '거래일 최고 거래대금 & 거래대금 '
        . number_format($MINAMT / 1e8) . '억↑ & 20평비 ' . $MULT . '배↑');
    say('★★같은 날 «둘 다» 닿은 건은 「모호」로 따로 센다 — 일봉은 그 날 안의 순서를 모른다.');
    say('★거래정지일(vol=0)은 «건너뛴다» — 그 날은 체결이 불가능했다(qm_day_kind).');

    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt,list_shrs FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $codes = $pdo->query("SELECT DISTINCT code FROM krx_amt ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);

    /* 신호 하나 = ['chg','yr','kind','opt','pes','gap']
     *   kind: tp 익절 · sl 손절 · amb 모호 · non 미도달
     *   opt/pes: 모호를 익절/손절로 각각 해석한 수익률 */
    $rows = [];
    $nHaltSig = 0;      // 사후 창에 거래정지일이 있어 «건너뛰며» 판정한 신호
    $nGapSig  = 0;      // 진짜 결측이 끼어 판정을 접은 신호
    $nNoDay   = 0;      // 창 안에 거래 가능한 날이 아예 없던 신호
    foreach ($codes as $ci => $code) {
        if (substr($code, -1) !== '0' || isset($etf[$code])) continue;
        $nm = (string)($names[$code] ?? '');
        if ($nm !== '' && (mb_strpos($nm, '스팩') !== false || stripos($nm, 'ETN') !== false)) continue;
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $n = count($s);
        if ($n < $WIN + $DAYS + 2) continue;

        for ($i = $WIN; $i < $n - $DAYS - 1; $i++) {
            $amt = (float)$s[$i]['amt'];
            if ($amt < $MINAMT) continue;
            $mx = 0.0;
            for ($k = $i - ($WIN - 1); $k < $i; $k++) $mx = max($mx, (float)$s[$k]['amt']);
            if ($amt <= $mx) continue;
            $a20 = 0.0;
            for ($k = $i - 20; $k < $i; $k++) $a20 += (float)$s[$k]['amt'];
            $a20 /= 20;
            if ($a20 <= 0 || $amt / $a20 < $MULT) continue;      // 불꽃형만

            $ls = (int)$s[$i]['list_shrs']; $bad = false;
            for ($k = $i; $k <= min($n - 1, $i + $DAYS + 1); $k++) {
                $x = (int)$s[$k]['list_shrs'];
                if ($ls > 0 && $x > 0 && abs($x / $ls - 1) > 0.05) { $bad = true; break; }
            }
            if ($bad) continue;

            $base = (float)$s[$i]['c'];                          // ★신호일 «종가»에 산다
            if ($base <= 0) continue;
            $prev = (float)$s[$i - 1]['c'];

            $kind = 'non'; $opt = null; $pes = null; $gap = 0;
            /* ★사후 창은 «어떤 날인가»부터 가른다 (qm_day_kind 주석).
             *   halt = 거래정지 → 그 날은 못 판다. 건너뛰고 다음 날로 간다.
             *   gap  = 진짜 결측 → 고·저를 모르니 이 신호는 판정하지 않는다. */
            $hadHalt = false; $hadGap = false; $lastOk = null;
            for ($j = 1; $j <= $DAYS; $j++) {
                $p  = $s[$i + $j];
                $dk = qm_day_kind($p);
                if ($dk === 'gap')  { $hadGap = true; break; }
                if ($dk === 'halt') { $hadHalt = true; continue; }
                $lastOk = $p;
                $op = ((float)$p['o'] / $base - 1) * 100;
                $hp = ((float)$p['h'] / $base - 1) * 100;
                $lp = ((float)$p['l'] / $base - 1) * 100;

                /* ①갭 — 브래킷은 갭을 못 막는다. 시가가 이미 넘어섰으면 «그 시가»가 체결가다. */
                if ($op >= $TP)  { $kind = 'tp'; $opt = $pes = $op; $gap = 1; break; }
                if ($op <= -$SL) { $kind = 'sl'; $opt = $pes = $op; $gap = 1; break; }
                /* ②같은 날 둘 다 — 순서를 «모른다» */
                if ($hp >= $TP && $lp <= -$SL) { $kind = 'amb'; $opt = $TP; $pes = -$SL; break; }
                if ($hp >= $TP)  { $kind = 'tp'; $opt = $pes = $TP;  break; }
                if ($lp <= -$SL) { $kind = 'sl'; $opt = $pes = -$SL; break; }
            }
            if ($hadGap) { $nGapSig++; continue; }
            if ($kind === 'non') {
                /* ★청산가는 «마지막 거래 가능일»의 종가다 — D+5 가 정지일이면 그 종가는
                 *   직전가를 이월한 값이라 «팔 수 없던 값»이다. */
                if ($lastOk === null) { $nNoDay++; continue; }
                $opt = $pes = ((float)$lastOk['c'] / $base - 1) * 100;
            }
            if ($hadHalt) $nHaltSig++;
            $rows[] = ['chg' => $prev > 0 ? ($base / $prev - 1) * 100 : 0,
                       'yr' => (int)substr($s[$i]['d'], 0, 4),
                       'kind' => $kind, 'opt' => $opt, 'pes' => $pes, 'gap' => $gap];
        }
        if ($ci % 600 === 0) say('  … ' . $ci . '종목 · 신호 ' . number_format(count($rows)));
    }
    say('  불꽃형 신호 ' . number_format(count($rows)) . '건');
    say(sprintf('  ★거래정지일을 건너뛰며 판정한 신호 %s건 · 진짜 결측이라 판정 접은 것 %s건 '
        . '· 창 안에 거래일이 없던 것 %s건',
        number_format($nHaltSig), number_format($nGapSig), number_format($nNoDay)));

    $report = function (string $title, array $g) use ($TP, $SL, $DAYS, $COST) {
        hr($title . '  (n=' . number_format(count($g)) . ')');
        if (!$g) { say('    표본 없음'); return; }
        $n = count($g);
        $c = ['tp' => 0, 'sl' => 0, 'amb' => 0, 'non' => 0];
        foreach ($g as $r) $c[$r['kind']]++;
        say(sprintf('    익절 도달   %6s (%5.1f%%)', number_format($c['tp']), $c['tp'] / $n * 100));
        say(sprintf('    손절 도달   %6s (%5.1f%%)', number_format($c['sl']), $c['sl'] / $n * 100));
        say(sprintf('    ★모호(같은 날 둘 다) %6s (%5.1f%%) — 일봉으론 순서를 모른다',
            number_format($c['amb']), $c['amb'] / $n * 100));
        say(sprintf('    미도달→D+%d 종가 %6s (%5.1f%%)', $DAYS, number_format($c['non']),
            $c['non'] / $n * 100));
        $gp = count(array_filter($g, fn($r) => $r['gap'] === 1));
        say(sprintf('    ※그중 «갭으로» 뚫린 건 %s (%.1f%%) — 원하는 값이 아니라 시가에 체결된다',
            number_format($gp), $gp / $n * 100));
        say('');
        foreach ([['낙관(모호=익절)', 'opt'], ['비관(모호=손절)', 'pes']] as [$lab, $k]) {
            $v = array_map(fn($r) => $r[$k], $g);
            $s = qm_stat($v);
            $wr = count(array_filter($v, fn($x) => $x > 0)) / $n * 100;
            $wc = count(array_filter($v, fn($x) => $x - $COST > 0)) / $n * 100;
            say(sprintf('    %-16s 평균 %7.2f%%  중앙 %7.2f%%  승률 %5.1f%%   '
                . '| 비용 %.2f%% 뒤 평균 %+.2f%% · 승률 %5.1f%%',
                $lab, $s['mean'], $s['med'], $wr, $COST, $s['mean'] - $COST, $wc));
        }
    };

    $noLim = array_values(array_filter($rows, fn($r) => $r['chg'] < 29));
    $report('R1. 불꽃형 전체', $rows);
    $report('R2. 불꽃형 · 상한가 제외 (종가에 실제로 살 수 있는 것)', $noLim);

    hr('R3. 연도별 — 상한가 제외 · 비관 기준(모호=손절) 평균');
    say(sprintf('  %-6s %8s %10s %10s %10s', '연도', 'n', '익절%', '손절%', '비관 평균'));
    $yrs = array_values(array_unique(array_map(fn($r) => $r['yr'], $noLim)));
    sort($yrs);
    $pos = 0; $cnt = 0;
    foreach ($yrs as $y) {
        $g = array_values(array_filter($noLim, fn($r) => $r['yr'] === $y));
        if (!$g) continue;
        $tp = count(array_filter($g, fn($r) => $r['kind'] === 'tp')) / count($g) * 100;
        $sl = count(array_filter($g, fn($r) => $r['kind'] === 'sl')) / count($g) * 100;
        $m  = qm_stat(array_map(fn($r) => $r['pes'], $g));
        $cnt++; if (($m['mean'] ?? 0) - $COST > 0) $pos++;
        say(sprintf('  %-6d %8s %9.1f%% %9.1f%% %9.2f%%', $y, number_format(count($g)), $tp, $sl, $m['mean']));
    }
    say(sprintf('  ★비용 뒤 평균이 «양(+)»인 해: %d / %d', $pos, $cnt));

    say('');
    say('  ⛔모호 구간이 크면 이 표로는 승률을 «말할 수 없다» — 그때는 분봉으로 순서를 가려야 한다.');
    say('  ⛔호가 잔량·슬리피지 미반영. 손절은 «닿으면 그 값에 팔린다»고 보았다 — 실제로는 더 밀린다.');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    break;
}
// ══════════════════════════════════════════════════════════════════════════
//  ⛔ job=flamefill 은 2026-08-09 에 <b>삭제됐다</b> — 표 `qm_flame` 도 함께 DROP.
//
//  왜 — 그 화면(퀀트 > 패턴분석(불꽃형))은 「불꽃형 전수 1,528건을 훑는」 자리였는데,
//  다 훑고 나면 할 일이 없고 새 신호도 안 쌓였다(1회성 스냅샷이었다). 사용자가 거기서
//  담은 관심차트 365건을 «정의»로 삼아 <b>매일 쌓는 화면</b>으로 합치라고 정했다.
//
//  후신 — 표 `bx_cand` · 판정 `stock/lib/boxbrk.php` · 적재 `cron/bx_scan.php` ·
//         화면 `?mode=boxbrk`(패턴분석 (박스 상향돌파)). 담긴 365건은 src='pick' 으로 옮겼다.
//
//  ★이 파일의 8년 분석 잡들(dbrk·mbrk·dp5·dbo…)은 `qm_flame` 을 읽지 않는다 —
//    `krx_amt`·`qm_bar` 를 직접 훑으므로 이 삭제에 영향받지 않는다(2026-08-09 전수 확인).
// ══════════════════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════════════════
//  job=mbrk tp=15 sl=10 days=5 — 브래킷을 «분봉»으로 다시 판정한다. API 콜 0
//
//  job=dbrk 는 일봉이라 「같은 날 둘 다 닿음」의 순서를 모른다. 분봉은 안다.
//  ★★그리고 이 잡의 진짜 값어치는 <b>같은 표본에서 일봉 판정과 분봉 판정을 나란히 놓는 것</b>이다
//    — 「일봉 브래킷 백테스트를 믿어도 되나」의 답이 거기서 나온다.
//
//  ⚠표본 한계를 «먼저» 적는다: `qm_bar` 는 급등(+10%·100억) 이벤트만 담는다.
//    그래서 여기 표본은 <b>불꽃형 ∩ 급등</b>이다 — 「+10% 안 오른 불꽃형」은 분봉이 아예 없다.
//    이건 부분집합이지 불꽃형 전체가 아니다. 결과를 전체로 넓혀 읽으면 안 된다.
// ══════════════════════════════════════════════════════════════════════════
case 'mbrk': {
    $TP = (float)($_GET['tp'] ?? 15);
    $SL = (float)($_GET['sl'] ?? 10);
    $DAYS = max(1, min(5, (int)($_GET['days'] ?? 5)));
    $COST = 0.20;
    $WIN = 120; $MINAMT = 10000000000; $MULT = 20;

    $rg = $pdo->query("SELECT MIN(DATE(ts)) a, MAX(DATE(ts)) b FROM qm_bar")->fetch(PDO::FETCH_ASSOC);
    say('불꽃형 브래킷 — «분봉»으로 판정  (' . date('Y-m-d H:i') . ')');
    say(sprintf('규칙: 신호일 종가 매수 → %d거래일 안에 +%.1f%% 익절 / −%.1f%% 손절 (분봉 순서대로)',
        $DAYS, $TP, $SL));
    say('분봉 원장 구간: ' . $rg['a'] . ' ~ ' . $rg['b']);
    say('⚠표본은 «불꽃형 ∩ 급등(+10%)»이다 — qm_bar 가 급등 이벤트만 담기 때문이다.');
    say('   「+10% 안 오른 불꽃형」은 분봉이 없어 못 잰다. 부분집합이지 불꽃형 전체가 아니다.');

    /* ① 분봉 구간 안의 불꽃형 신호를 krx_amt 에서 찾는다 */
    $etf = qm_etf_codes($pdo);
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info")->fetchAll(PDO::FETCH_ASSOC)
             as $r) $names[$r['stock_code']] = $r['stock_name'];
    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt,list_shrs FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $codes = $pdo->query("SELECT DISTINCT code FROM krx_amt ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);

    $sig = [];                                    // code => [ d => ['chg'=>, 'dkind'=>, 'dopt'=>, 'dpes'=>] ]
    $nSig = 0; $nSkipDay = 0;
    foreach ($codes as $code) {
        if (substr($code, -1) !== '0' || isset($etf[$code])) continue;
        $nm = (string)($names[$code] ?? '');
        if ($nm !== '' && (mb_strpos($nm, '스팩') !== false || stripos($nm, 'ETN') !== false)) continue;
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $n = count($s);
        if ($n < $WIN + $DAYS + 2) continue;
        for ($i = $WIN; $i < $n - $DAYS - 1; $i++) {
            if ($s[$i]['d'] < $rg['a'] || $s[$i]['d'] > $rg['b']) continue;
            $amt = (float)$s[$i]['amt'];
            if ($amt < $MINAMT) continue;
            $mx = 0.0;
            for ($k = $i - ($WIN - 1); $k < $i; $k++) $mx = max($mx, (float)$s[$k]['amt']);
            if ($amt <= $mx) continue;
            $a20 = 0.0;
            for ($k = $i - 20; $k < $i; $k++) $a20 += (float)$s[$k]['amt'];
            $a20 /= 20;
            if ($a20 <= 0 || $amt / $a20 < $MULT) continue;

            /* ★일봉 판정도 «여기서» 같이 낸다 — 뒤에서 분봉 판정과 한 표본으로 견주려면
             *   두 판정이 반드시 같은 신호 집합 위에 있어야 한다. */
            $base = (float)$s[$i]['c']; $prev = (float)$s[$i - 1]['c'];
            if ($base <= 0) continue;
            $dk = 'non'; $dopt = $dpes = null;
            /* ★일봉 쪽도 dbrk 와 «같은 자»로 잰다 — 거래정지일은 건너뛰고 진짜 결측은 접는다.
             *   여기서 자가 다르면 뒤의 「일봉 vs 분봉」 일치율이 두 잣대의 차이를 재게 된다. */
            $hadGap = false; $lastOk = null;
            for ($j = 1; $j <= $DAYS; $j++) {
                $p = $s[$i + $j];
                $kd = qm_day_kind($p);
                if ($kd === 'gap')  { $hadGap = true; break; }
                if ($kd === 'halt') continue;
                $lastOk = $p;
                $op = ((float)$p['o'] / $base - 1) * 100;
                $hp = ((float)$p['h'] / $base - 1) * 100;
                $lp = ((float)$p['l'] / $base - 1) * 100;
                if ($op >= $TP)  { $dk = 'tp'; $dopt = $dpes = $op; break; }
                if ($op <= -$SL) { $dk = 'sl'; $dopt = $dpes = $op; break; }
                if ($hp >= $TP && $lp <= -$SL) { $dk = 'amb'; $dopt = $TP; $dpes = -$SL; break; }
                if ($hp >= $TP)  { $dk = 'tp'; $dopt = $dpes = $TP;  break; }
                if ($lp <= -$SL) { $dk = 'sl'; $dopt = $dpes = -$SL; break; }
            }
            if ($hadGap) { $nSkipDay++; continue; }
            if ($dk === 'non') {
                if ($lastOk === null) { $nSkipDay++; continue; }
                $dopt = $dpes = ((float)$lastOk['c'] / $base - 1) * 100;
            }
            $sig[$code][$s[$i]['d']] = ['chg' => $prev > 0 ? ($base / $prev - 1) * 100 : 0,
                                        'dkind' => $dk, 'dopt' => $dopt, 'dpes' => $dpes];
            $nSig++;
        }
    }
    say('  분봉 구간 안의 불꽃형 신호 ' . number_format($nSig) . '건 (' . number_format(count($sig)) . '종목)');
    if ($nSkipDay) say('  ★일봉으로 판정할 수 없어 뺀 신호 ' . number_format($nSkipDay)
        . '건 (진짜 결측 · 또는 창 안에 거래일 없음)');

    /* ② 분봉으로 다시 판정 */
    $bar = $pdo->prepare("SELECT ts, o, h, l, c FROM qm_bar WHERE code=? ORDER BY ts");
    $out = []; $noBar = 0;
    foreach ($sig as $code => $ds) {
        $bar->execute([$code]);
        $byDay = [];
        foreach ($bar->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $byDay[substr($b['ts'], 0, 10)][] = [(float)$b['o'], (float)$b['h'], (float)$b['l'], (float)$b['c']];
        }
        if (!$byDay) { $noBar += count($ds); continue; }
        $days = array_keys($byDay); sort($days);
        foreach ($ds as $d => $info) {
            if (!isset($byDay[$d])) { $noBar++; continue; }
            $base = (float)end($byDay[$d])[3];           // ★신호일 마지막 분봉 종가
            if ($base <= 0) { $noBar++; continue; }
            $post = array_slice(array_values(array_filter($days, fn($x) => $x > $d)), 0, $DAYS);
            if (count($post) < $DAYS) { $noBar++; continue; }

            $kind = 'non'; $ret = null;
            foreach ($post as $pd) {
                foreach ($byDay[$pd] as $b) {
                    $op = ($b[0] / $base - 1) * 100;
                    $hp = ($b[1] / $base - 1) * 100;
                    $lp = ($b[2] / $base - 1) * 100;
                    /* 1분 봉 «시가»가 이미 넘었으면 그 값이 체결가다 (갭·급변) */
                    if ($op >= $TP)  { $kind = 'tp'; $ret = $op; break 2; }
                    if ($op <= -$SL) { $kind = 'sl'; $ret = $op; break 2; }
                    /* ★한 «1분» 안에서 둘 다 닿은 경우만 모호다 — 일봉의 「하루」와 견주면 아주 좁다 */
                    if ($hp >= $TP && $lp <= -$SL) { $kind = 'amb'; $ret = null; break 2; }
                    if ($hp >= $TP)  { $kind = 'tp'; $ret = $TP;  break 2; }
                    if ($lp <= -$SL) { $kind = 'sl'; $ret = -$SL; break 2; }
                }
            }
            if ($kind === 'non') $ret = ((float)end($byDay[end($post)])[3] / $base - 1) * 100;
            $out[] = $info + ['mkind' => $kind, 'mret' => $ret];
        }
    }
    say('  분봉으로 판정한 것 ' . number_format(count($out)) . '건 · 분봉 없음 ' . number_format($noBar)
        . '건 (그 신호일에 +10% 급등이 아니었다)');
    if (!$out) { say('★표본이 없다.'); break; }

    $noLim = array_values(array_filter($out, fn($r) => $r['chg'] < 29));
    foreach ([['S1. 분봉 판정 — 전체', $out], ['S2. 분봉 판정 — 상한가 제외', $noLim]] as [$title, $g]) {
        hr($title . '  (n=' . number_format(count($g)) . ')');
        $n = count($g);
        $c = ['tp' => 0, 'sl' => 0, 'amb' => 0, 'non' => 0];
        foreach ($g as $r) $c[$r['mkind']]++;
        foreach (['tp' => '익절 도달', 'sl' => '손절 도달', 'amb' => '★모호(같은 1분 봉 안)',
                  'non' => '미도달→종가'] as $k => $lab) {
            say(sprintf('    %-22s %6s (%5.1f%%)', $lab, number_format($c[$k]), $c[$k] / $n * 100));
        }
        $v = array_values(array_filter(array_map(fn($r) => $r['mret'], $g), fn($x) => $x !== null));
        $st = qm_stat($v);
        $wr = $v ? count(array_filter($v, fn($x) => $x > 0)) / count($v) * 100 : 0;
        $wc = $v ? count(array_filter($v, fn($x) => $x - $COST > 0)) / count($v) * 100 : 0;
        say(sprintf('    분봉 기준  평균 %7.2f%%  중앙 %7.2f%%  승률 %5.1f%%  '
            . '| 비용 %.2f%% 뒤 평균 %+.2f%% · 승률 %5.1f%%',
            $st['mean'] ?? 0, $st['med'] ?? 0, $wr, $COST, ($st['mean'] ?? 0) - $COST, $wc));
    }

    /* ③ ★★이 잡의 핵심 — 같은 표본에서 일봉 판정이 분봉 판정과 얼마나 달랐나 */
    hr('S3. ★★일봉 판정 vs 분봉 판정 — 같은 신호 (상한가 제외 n=' . number_format(count($noLim)) . ')');
    $lab = ['tp' => '익절', 'sl' => '손절', 'amb' => '모호', 'non' => '미도달'];
    say(sprintf('  %-10s %8s %8s %8s %8s', '일봉\\분봉', '익절', '손절', '모호', '미도달'));
    $mis = 0;
    foreach (['tp', 'sl', 'amb', 'non'] as $dk) {
        $r = [];
        foreach (['tp', 'sl', 'amb', 'non'] as $mk) {
            $r[$mk] = count(array_filter($noLim, fn($x) => $x['dkind'] === $dk && $x['mkind'] === $mk));
            if ($dk !== $mk && $dk !== 'amb') $mis += $r[$mk];
        }
        say(sprintf('  %-10s %8s %8s %8s %8s', $lab[$dk], number_format($r['tp']), number_format($r['sl']),
            number_format($r['amb']), number_format($r['non'])));
    }
    say(sprintf('  ★일봉이 «틀리게» 판정한 건: %s / %s (%.1f%%) — 모호였던 건은 뺐다',
        number_format($mis), number_format(count($noLim)), count($noLim) ? $mis / count($noLim) * 100 : 0));
    $da = qm_stat(array_map(fn($r) => $r['dpes'], $noLim));
    $do = qm_stat(array_map(fn($r) => $r['dopt'], $noLim));
    $mv = array_values(array_filter(array_map(fn($r) => $r['mret'], $noLim), fn($x) => $x !== null));
    $ms = qm_stat($mv);
    say('');
    say(sprintf('  같은 표본의 평균 —  일봉 낙관 %+.2f%%  ·  일봉 비관 %+.2f%%  ·  ★분봉(진짜) %+.2f%%',
        $do['mean'] ?? 0, $da['mean'] ?? 0, $ms['mean'] ?? 0));
    say('  ⇒ 분봉 값이 두 경계 «밖»에 있으면 일봉 시뮬 자체가 틀린 것이다(갭 처리·순서 말고 다른 이유).');

    say('');
    say('  ⛔이 표본은 «불꽃형 ∩ 급등»이다 — 불꽃형 전체로 넓혀 읽지 않는다.');
    say('  ⛔호가 잔량·슬리피지 미반영. 「닿았다」를 「그 값에 팔렸다」로 본다.');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=mfill — 「사후 고점이 몇 시에 오는가」를 분봉에서 채운다. API 콜 0 · 멱등
//
//  일봉(job=dmfe)이 「D+1 에 42.8%」까지 답했다. 그 하루 «안»은 분봉만 안다.
//
//  ★★기준가는 <b>이벤트일 마지막 분봉의 종가</b>다 — krx_amt 종가를 쓰면 안 된다.
//    분봉은 upd_stkpc_tp=1(지금 기준 수정주가)이고 krx_amt 는 «그 날 값»이라
//    증자·분할이 끼면 상수배로 어긋난다(§11 · 실측 4.1%). 한 소스 안에서만 잰다.
//  ★거래량 «비중»으로 체결 가능성을 본다 — 「그 값이 있었다」와 「거기서 손이 바뀌었다」는 다르다.
//    ⛔단 이것도 «체결된 양»이지 호가 잔량이 아니다. 내 주문이 소화된다는 보장은 못 준다.
// ══════════════════════════════════════════════════════════════════════════
case 'mfill': {
    $TGT = [3 => 'm_t3_hm', 5 => 'm_t5_hm', 10 => 'm_t10_hm'];
    $cols = [
        'm_npost_d'     => "TINYINT NULL COMMENT '분봉이 실린 사후 거래일 수 (0~5)'",
        'm_mfe_day'     => "TINYINT NULL COMMENT 'D+1~D+5 최고가 봉의 날'",
        'm_mfe_hm'      => "VARCHAR(5) NULL COMMENT '그 봉의 시각 HH:MM'",
        'm_mfe_ret'     => "DECIMAL(6,2) NULL COMMENT '그 고가/이벤트일 마지막봉 종가-1'",
        'm_mfe_vr'      => "DECIMAL(6,3) NULL COMMENT '그 봉 거래량 / 그 날 거래량 (%)'",
        'm_nd_hi_hm'    => "VARCHAR(5) NULL COMMENT 'D+1 고가 시각'",
        'm_nd_hi_ret'   => "DECIMAL(6,2) NULL",
        'm_nd_hi_vr'    => "DECIMAL(6,3) NULL COMMENT 'D+1 고가 봉의 거래량 비중 (%)'",
        'm_nd_lo_hm'    => "VARCHAR(5) NULL COMMENT 'D+1 «저가» 봉의 시각 — 고가보다 먼저인가'",
        'm_nd_open_ret' => "DECIMAL(6,2) NULL COMMENT 'D+1 09:00 봉 시가 기준'",
        'm_nd_close_ret'=> "DECIMAL(6,2) NULL",
    ];
    foreach ($TGT as $x => $c) $cols[$c] = "VARCHAR(5) NULL COMMENT 'D+1 안에서 +{$x}% 에 닿은 시각'";
    foreach ($cols as $c => $def) {
        try { $pdo->exec("ALTER TABLE qm_feat ADD COLUMN IF NOT EXISTS {$c} {$def}"); }
        catch (Throwable $e) { say('  (컬럼 ' . $c . ' 추가 실패: ' . $e->getMessage() . ')'); }
    }

    $codes = $pdo->query("SELECT DISTINCT code FROM qm_event ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
    say('사후 고점 «시각» 계산 — 종목 ' . number_format(count($codes)) . '개 · API 콜 0');
    say('★기준가 = 이벤트일 «마지막 분봉»의 종가 (krx_amt 를 섞지 않는다 — §11 수정주가 함정)');

    $evs = $pdo->prepare("SELECT d FROM qm_event WHERE code=? ORDER BY d");
    /* ★한 번에 다 읽는다 — o·h·l·c·v 가 한 행에 있는데 나눠 읽고 시각으로 맞추면
     *   봉마다 그 날 봉을 훑게 되어 1,960만 × 381 이 된다(안 끝난다). */
    $bar = $pdo->prepare("SELECT ts, o, h, l, c, v FROM qm_bar WHERE code=? ORDER BY ts");
    $set = implode(',', array_map(fn($c) => "{$c}=?", array_keys($cols)));
    $upd = $pdo->prepare("UPDATE qm_feat SET {$set} WHERE code=? AND d=?");

    $nRow = 0; $nFull = 0; $nNoBase = 0;
    $pdo->beginTransaction();
    foreach ($codes as $ci => $code) {
        $bar->execute([$code]);
        $byDay = [];                                   // 'YYYY-MM-DD' => [[hm,o,h,c,v,l], …]
        foreach ($bar->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $byDay[substr($b['ts'], 0, 10)][] = [substr($b['ts'], 11, 5),
                (float)$b['o'], (float)$b['h'], (float)$b['c'], (float)$b['v'], (float)$b['l']];
        }
        if (!$byDay) continue;
        $days = array_keys($byDay);
        sort($days);

        $evs->execute([$code]);
        foreach ($evs->fetchAll(PDO::FETCH_COLUMN) as $ed) {
            /* ★기준가 = 이벤트일 마지막 봉의 종가 */
            $d0 = $byDay[$ed] ?? null;
            if (!$d0) { $nNoBase++; continue; }
            $base = (float)end($d0)[3];
            if ($base <= 0) { $nNoBase++; continue; }

            $post = array_values(array_filter($days, fn($x) => $x > $ed));
            $post = array_slice($post, 0, 5);
            $nd   = count($post);
            if (!$nd) { $nNoBase++; continue; }

            $mfe = null; $mfeDay = null; $mfeHm = null; $mfeVr = null;
            $ndHiHm = $ndHiRet = $ndHiVr = $ndLoHm = $ndOpen = $ndClose = null;
            $tgtHm = array_fill_keys(array_keys($TGT), null);

            foreach ($post as $j => $pd) {
                $bs = $byDay[$pd];
                $dayVol = 0.0;
                foreach ($bs as $x) $dayVol += $x[4];
                foreach ($bs as $x) {
                    $r = ($x[2] / $base - 1) * 100;                       // 고가 기준
                    if ($mfe === null || $r > $mfe) {
                        $mfe = $r; $mfeDay = $j + 1; $mfeHm = $x[0];
                        $mfeVr = $dayVol > 0 ? $x[4] / $dayVol * 100 : null;
                    }
                }
                if ($j !== 0) continue;                                   // 아래는 D+1 전용

                $ndOpen  = round(((float)$bs[0][1] / $base - 1) * 100, 2);
                $ndClose = round(((float)end($bs)[3] / $base - 1) * 100, 2);
                $hi = null; $lo = null;
                foreach ($bs as $x) {
                    if ($hi === null || $x[2] > $hi) {
                        $hi = $x[2]; $ndHiHm = $x[0];
                        $ndHiVr = $dayVol > 0 ? round($x[4] / $dayVol * 100, 3) : null;
                    }
                    if ($lo === null || $x[5] < $lo) { $lo = $x[5]; $ndLoHm = $x[0]; }   // 실제 저가
                }
                $ndHiRet = round(($hi / $base - 1) * 100, 2);
                foreach ($TGT as $x => $cName) {
                    foreach ($bs as $b2) {
                        if (($b2[1] / $base - 1) * 100 >= $x || ($b2[2] / $base - 1) * 100 >= $x) {
                            $tgtHm[$x] = $b2[0]; break;
                        }
                    }
                }
            }

            $vals = [$nd, $mfeDay, $mfeHm, $mfe === null ? null : round($mfe, 2),
                     $mfeVr === null ? null : round($mfeVr, 3),
                     $ndHiHm, $ndHiRet, $ndHiVr, $ndLoHm, $ndOpen, $ndClose];
            foreach ($TGT as $x => $cName) $vals[] = $tgtHm[$x];
            $vals[] = $code; $vals[] = $ed;
            $upd->execute($vals);
            $nRow++;
            if ($nd >= 5) $nFull++;
        }
        if ($ci % 100 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        if ($ci % 300 === 0) say('  … ' . $ci . '종목 · 채운 이벤트 ' . number_format($nRow));
    }
    $pdo->commit();

    say('');
    say(sprintf('  채운 이벤트 %s · 그중 사후 5거래일 «분봉이 다 실린» 것 %s · 기준가/사후 없음 %s',
        number_format($nRow), number_format($nFull), number_format($nNoBase)));
    say('  ★분석은 «다 실린» 것만 쓴다 — 덜 실린 건을 섞으면 「며칠째」가 앞으로 쏠린다(일봉과 같은 규칙).');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=mtime — 「몇 시에 파나」. qm_feat 만 읽는다 · API 콜 0
// ══════════════════════════════════════════════════════════════════════════
case 'mtime': {
    $rows = $pdo->query("SELECT f.*, e.chg_pct FROM qm_feat f
                           JOIN qm_event e ON e.code=f.code AND e.d=f.d
                          WHERE f.m_npost_d >= 5 AND f.m_nd_hi_hm IS NOT NULL")
                ->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { say('★자료가 없다 — 먼저 job=mfill 을 돌린다.'); break; }
    $col  = fn(array $rs, string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $rs);
    $isLim = fn(array $r) => (float)$r['chg_pct'] >= 29.0;

    say('사후 고점은 «몇 시»에 오는가 — 분봉  (' . date('Y-m-d H:i') . ')');
    say('진입은 정해진 것으로 둔다 — 급등일 종가 매수. 묻는 것은 <b>청산 시각</b>뿐이다.');
    say('★전부 «분봉 안에서만» 계산 — 기준가는 이벤트일 마지막 봉 종가(§11 함정 없음).');
    say('★사후 5거래일 분봉이 다 실린 건만: ' . number_format(count($rows)) . '건');

    $clean = array_values(array_filter($rows,
        fn($r) => (int)$r['q_halted'] === 0 && (int)$r['q_split_after'] === 0));
    $noLim = array_values(array_filter($clean, fn($r) => !$isLim($r)));

    /* 시각 버킷 — 30분 단위. ★09:00 과 15:30 은 «단일가»라 따로 센다(성격이 다른 봉이다). */
    $BK = [['09:00', '09:00', '09:00 단일가'], ['09:01', '09:29', '09:01~09:29'],
           ['09:30', '09:59', '09:30~09:59'], ['10:00', '10:59', '10:00~10:59'],
           ['11:00', '11:59', '11:00~11:59'], ['12:00', '12:59', '12:00~12:59'],
           ['13:00', '13:59', '13:00~13:59'], ['14:00', '14:59', '14:00~14:59'],
           ['15:00', '15:19', '15:00~15:19'], ['15:30', '15:30', '15:30 단일가']];
    $hmDist = function (array $g, string $key) use ($BK) {
        $n = count($g);
        if (!$n) { say('    n=0'); return; }
        foreach ($BK as [$a, $b, $lab]) {
            $c = count(array_filter($g, fn($r) => $r[$key] !== null && $r[$key] >= $a && $r[$key] <= $b));
            $barw = (int)round($c / $n * 100 / 2);
            say(sprintf('      %-14s %5.1f%%  %6s  %s', $lab, $c / $n * 100, number_format($c),
                str_repeat('█', $barw)));
        }
    };

    hr('M1. ★D+1 고가는 몇 시에 오는가 — 일봉이 「D+1 에 42.8%」까지만 답한 그 하루');
    say('  [상한가 제외] n=' . number_format(count($noLim)));
    $hmDist($noLim, 'm_nd_hi_hm');
    say('');
    say('  [상한가 마감] n=' . number_format(count($clean) - count($noLim)));
    $hmDist(array_values(array_filter($clean, $isLim)), 'm_nd_hi_hm');

    hr('M2. 사후 5거래일 «전체»의 최고가 — 며칠째 · 몇 시');
    $dd = array_fill(1, 5, 0);
    foreach ($noLim as $r) { $x = (int)$r['m_mfe_day']; if ($x >= 1 && $x <= 5) $dd[$x]++; }
    $tot = max(1, array_sum($dd));
    $s = '';
    for ($i = 1; $i <= 5; $i++) $s .= sprintf(' D+%d %5.1f%%', $i, $dd[$i] / $tot * 100);
    say('    [상한가 제외] 며칠째:' . $s);
    say('    그 봉의 시각:');
    $hmDist($noLim, 'm_mfe_hm');

    hr('M3. ★그 시각에 «체결»이 있었나 — 값이 있어도 못 팔면 내 것이 아니다');
    say('  D+1 고가 봉의 거래량 비중(그 날 거래량 대비 %) · 5일 최고가 봉도 같은 자로');
    foreach ([['D+1 고가 봉', 'm_nd_hi_vr'], ['5일 최고가 봉', 'm_mfe_vr']] as [$nm, $c]) {
        $st = qm_stat($col($noLim, $c));
        if (!$st['n']) continue;
        say(sprintf('    %-14s n=%6d  평균 %6.3f%%  중앙 %6.3f%%  25/75 %6.3f/%6.3f',
            $nm, $st['n'], $st['mean'], $st['med'], $st['p25'], $st['p75']));
    }
    $zero = count(array_filter($noLim, fn($r) => $r['m_nd_hi_vr'] !== null && (float)$r['m_nd_hi_vr'] <= 0));
    say(sprintf('    ★거래량 0 인 고가 봉: %s건 (%.2f%%)', number_format($zero),
        count($noLim) ? $zero / count($noLim) * 100 : 0));
    say('    ⛔이것은 «체결된 양»이지 호가 잔량이 아니다 — 내 주문이 소화된다는 보장은 못 준다.');

    hr('M4. 시가 매도 vs D+1 안 목표가 매도 — 도달률과 «도달 시각»');
    $o = qm_stat($col($noLim, 'm_nd_open_ret'));
    $c1 = qm_stat($col($noLim, 'm_nd_close_ret'));
    $hh = qm_stat($col($noLim, 'm_nd_hi_ret'));
    foreach ([['D+1 시가에 판다', $o], ['D+1 종가에 판다', $c1], ['D+1 고가(위쪽 한계)', $hh]] as [$nm, $st]) {
        if (!$st['n']) continue;
        say(sprintf('    %-20s n=%6d  평균 %7.2f%%  중앙 %7.2f%%  양(+) %5.1f%%',
            $nm, $st['n'], $st['mean'], $st['med'], $st['win']));
    }
    say('');
    foreach ([3 => 'm_t3_hm', 5 => 'm_t5_hm', 10 => 'm_t10_hm'] as $x => $c) {
        $hit = array_values(array_filter($noLim, fn($r) => $r[$c] !== null));
        say(sprintf('    +%-2d%% 도달  %5.1f%% (%s건) — 도달 시각 분포:', $x,
            count($noLim) ? count($hit) / count($noLim) * 100 : 0, number_format(count($hit))));
        $hmDist($hit, $c);
        say('');
    }

    hr('M5. 첫 30분 몰림(이벤트일)과 D+1 고점 시각이 같은 것을 말하는가');
    say('  이르게 몰린 종목은 다음날 고점도 이른가 — 두 축을 잇는 물음이다.');
    /* ★f_vol30_ratio 는 «비율»(0~1)이지 퍼센트가 아니다 — 10/20/30 으로 자르면 전부 첫 구간에
     *   들어가 표가 한 줄이 된다(2026-08-06 실측: 6,066건이 통째로 들어갔다).
     *   analyze 의 가설4 와 «같은 경계»를 써야 두 표를 견줄 수 있다. */
    say(sprintf('    %-12s %7s %12s %12s', '첫30분 비중', 'n', 'D+1 고가 오전%', 'D+1 고가 평균'));
    foreach ([[0, .10, '10% 미만'], [.10, .20, '10~20%'], [.20, .30, '20~30%'], [.30, 9, '30% 이상']]
             as [$lo, $hi, $lab]) {
        $g = array_values(array_filter($noLim, fn($r) => $r['f_vol30_ratio'] !== null
            && (float)$r['f_vol30_ratio'] >= $lo && (float)$r['f_vol30_ratio'] < $hi));
        if (!$g) continue;
        $am = count(array_filter($g, fn($r) => $r['m_nd_hi_hm'] !== null && $r['m_nd_hi_hm'] < '12:00'));
        $st = qm_stat($col($g, 'm_nd_hi_ret'));
        say(sprintf('    %-12s %7s %11.1f%% %11.2f%%', $lab, number_format(count($g)),
            $am / count($g) * 100, $st['mean'] ?? 0));
    }

    hr('M6. D+1 안에서 고가가 먼저인가, 저가가 먼저인가');
    say('  둘 다 봉의 실제 고가·저가로 잰다. 같은 봉이면 그 안의 순서는 «모른다»(1분 안은 담기지 않는다).');
    $a = count(array_filter($noLim, fn($r) => $r['m_nd_lo_hm'] !== null && $r['m_nd_hi_hm'] < $r['m_nd_lo_hm']));
    $b = count(array_filter($noLim, fn($r) => $r['m_nd_lo_hm'] !== null && $r['m_nd_hi_hm'] > $r['m_nd_lo_hm']));
    $e = count(array_filter($noLim, fn($r) => $r['m_nd_lo_hm'] !== null && $r['m_nd_hi_hm'] === $r['m_nd_lo_hm']));
    $n = max(1, $a + $b + $e);
    say(sprintf('    고가 먼저 %5.1f%%   저가 먼저 %5.1f%%   같은 봉 %5.1f%%  (n=%s)',
        $a / $n * 100, $b / $n * 100, $e / $n * 100, number_format($n)));

    say('');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    say('  ⛔분봉이 답하지 못하는 것: 호가 잔량 · 시간우선순위. 「그 값에 내 주문이 체결되나」는 여전히 모른다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
//  job=dp5 — 「패턴5(소문난 잔치)를 «하루만» 들면 어떤가」. API 콜 0
//
//  물음(2026-08-06 사용자): 패턴분석의 패턴5 실패 사례는 전부 <b>+20거래일 보유</b>다.
//  욕심 안 내고 다음날 팔면 확률이 좋지 않겠나.
//
//  ★★모집단이 다르다 — 이건 `qm_dday`(급등 +10%)가 아니라 <b>120일 최고 거래대금</b> 신호다.
//    거래대금 20평비도 «거래량» 배수가 아니라 «거래대금» 배수다. 그래서 krx_amt 를 다시 훑는다.
//  ★매수 시점은 검증 탭과 같게 «신호 다음날 종가» — 신호는 마감 후에 아는 것이다.
//    그래야 「중앙 −5.72% · 승률 37.4%」와 같은 자로 견줄 수 있다.
//  ⛔시장 대비 초과수익이 «아니다» — 검증 탭은 같은 날 전종목 중앙값을 뺀다.
//    짧은 보유(1~3일)는 시장 표류가 작아 큰 차이가 없지만, 20일 줄은 그만큼 부풀어 있다.
// ══════════════════════════════════════════════════════════════════════════
case 'dp5': {
    $WIN   = 120;                                   // 최고 거래대금 창 (KrxAmt::SURGE_WIN 과 같은 값)
    $MINAMT= 10000000000;                           // 하한 100억 (검증 탭 화면 기본값)
    $HOLD  = [1, 2, 3, 5, 10, 20];                  // 매수 뒤 보유 거래일
    $etf   = qm_etf_codes($pdo);
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info")->fetchAll(PDO::FETCH_ASSOC)
             as $r) $names[$r['stock_code']] = $r['stock_name'];

    say('패턴5 「소문난 잔치」를 «하루만» 들면 — 8년 일봉  (' . date('Y-m-d H:i') . ')');
    say('신호: 직전 ' . ($WIN - 1) . '거래일 최고 거래대금 초과 & 거래대금 '
        . number_format($MINAMT / 1e8) . '억 이상');
    say('★매수 = 신호 «다음날 종가»(검증 탭과 같은 기준) · 매도 = 그로부터 N거래일 뒤 종가');
    say('★「익일 시가」 줄은 매수 다음날 09:00 시가에 판 것이다 — 하루도 안 들고 있는 셈');
    say('⛔시장 대비 초과수익이 아니다 — 검증 탭의 −5.72% 는 초과수익이라 이 표와 «자가 다르다».');

    say('★거래정지일은 «판정에서 뺀다» — 사는 날이 정지면 그 신호를 버리고, 파는 날이 정지면'
        . ' 그 칸만 비운다(이월된 종가는 «팔 수 없던 값»이다 · qm_day_kind).');

    $sel = $pdo->prepare("SELECT d,o,c,vol,amt,list_shrs FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $codes = $pdo->query("SELECT DISTINCT code FROM krx_amt ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
    say('종목 ' . number_format(count($codes)) . '개를 훑는다…');

    /* 신호 하나당 한 줄: [20평비, 등락률, 연도, [보유일 => 수익률], 시가매도수익률] */
    $rows = [];
    $nNoBuy = 0;        // 사는 날(신호 다음날)이 거래정지·결측이라 버린 신호
    foreach ($codes as $ci => $code) {
        if (substr($code, -1) !== '0' || isset($etf[$code])) continue;
        $nm = (string)($names[$code] ?? '');
        if ($nm !== '' && (mb_strpos($nm, '스팩') !== false || stripos($nm, 'ETN') !== false)) continue;
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $n = count($s);
        if ($n < $WIN + 25) continue;

        for ($i = $WIN; $i < $n - 22; $i++) {
            $amt = (float)$s[$i]['amt'];
            if ($amt < $MINAMT) continue;
            $mx = 0.0;
            for ($k = $i - ($WIN - 1); $k < $i; $k++) $mx = max($mx, (float)$s[$k]['amt']);
            if ($amt <= $mx) continue;                          // 120일 최고가 아니다

            $a20 = 0.0;
            for ($k = $i - 20; $k < $i; $k++) $a20 += (float)$s[$k]['amt'];
            $a20 /= 20;
            if ($a20 <= 0) continue;

            /* 분할이 낀 구간은 수익률 자체가 거짓이다 — 신호일부터 D+21 까지 본다 */
            $ls = (int)$s[$i]['list_shrs']; $bad = false;
            for ($k = $i; $k <= min($n - 1, $i + 21); $k++) {
                $x = (int)$s[$k]['list_shrs'];
                if ($ls > 0 && $x > 0 && abs($x / $ls - 1) > 0.05) { $bad = true; break; }
            }
            if ($bad) continue;

            /* ★사는 날이 거래정지면 «살 수 없었다» — 그 신호는 잡지 못한 것이라 버린다.
             *   이월된 종가로 사 두면 있지도 않은 체결을 표에 넣는 셈이다. */
            if (qm_day_kind($s[$i + 1]) !== 'ok') { $nNoBuy++; continue; }
            $buy = (float)$s[$i + 1]['c'];                       // ★신호 다음날 종가에 산다
            if ($buy <= 0) continue;
            $prev = (float)$s[$i - 1]['c'];
            $r = ['m' => $amt / $a20, 'chg' => $prev > 0 ? ((float)$s[$i]['c'] / $prev - 1) * 100 : 0,
                  'yr' => (int)substr($s[$i]['d'], 0, 4), 'h' => []];
            /* 파는 날이 정지·결측이면 그 칸은 «모른다»로 비운다 — qm_stat 이 NULL 을 세지 않는다 */
            $r['op'] = isset($s[$i + 2]) && qm_day_kind($s[$i + 2]) === 'ok'
                ? ((float)$s[$i + 2]['o'] / $buy - 1) * 100 : null;
            foreach ($HOLD as $h) {
                $r['h'][$h] = isset($s[$i + 1 + $h]) && qm_day_kind($s[$i + 1 + $h]) === 'ok'
                    ? ((float)$s[$i + 1 + $h]['c'] / $buy - 1) * 100 : null;
            }
            $rows[] = $r;
        }
        if ($ci % 500 === 0) say('  … ' . $ci . '종목 · 신호 ' . number_format(count($rows)));
    }
    say('  신호 ' . number_format(count($rows)) . '건'
        . ($nNoBuy ? ' · ★사는 날이 거래정지라 버린 신호 ' . number_format($nNoBuy) . '건' : ''));

    $col = fn(array $rs, $k) => array_map(fn($r) => is_int($k) ? $r['h'][$k] : $r[$k], $rs);
    $tbl = function (string $title, array $g) use ($col, $HOLD) {
        hr($title . '  (n=' . number_format(count($g)) . ')');
        if (!$g) { say('    표본 없음'); return; }
        say(sprintf('    %-14s %8s %9s %9s %8s', '매도 시점', 'n', '평균', '중앙', '승률'));
        $one = function (string $lab, array $v) {
            $s = qm_stat($v);
            if (!$s['n']) { say(sprintf('    %-14s %8s', $lab, 'n=0')); return; }
            say(sprintf('    %-14s %8s %8.2f%% %8.2f%% %7.1f%%%s', $lab, number_format($s['n']),
                $s['mean'], $s['med'], $s['win'], $s['n'] < 30 ? '  ←n 30 미만' : ''));
        };
        $one('익일 시가', $col($g, 'op'));
        foreach ($HOLD as $h) $one($h . '거래일 보유', $col($g, $h));
    };

    $flame = array_values(array_filter($rows, fn($r) => $r['m'] >= 20));
    $rest  = array_values(array_filter($rows, fn($r) => $r['m'] <  20));
    $tbl('P1. ★불꽃형 — 거래대금 20평비 20배 이상 (패턴5)', $flame);
    $tbl('P2. 대조군 — 같은 신호인데 20배 미만', $rest);
    $tbl('P3. 불꽃형 · 상한가(등락 29%↑) 제외', array_values(array_filter($flame, fn($r) => $r['chg'] < 29)));

    /* ★한 해만 좋은 것은 규칙이 아니다 — 짧은 보유가 «매년» 20일 보유를 이기는지 본다 */
    hr('P4. 연도별 — 불꽃형 (상한가 제외) · 1거래일 보유 vs 20거래일 보유');
    $fx = array_values(array_filter($flame, fn($r) => $r['chg'] < 29));
    $yrs = array_values(array_unique(array_map(fn($r) => $r['yr'], $fx)));
    sort($yrs);
    say(sprintf('  %-6s %8s %10s %10s %10s %10s', '연도', 'n', '1일 평균', '1일 중앙', '1일 승률', '20일 중앙'));
    $pos = 0; $cnt = 0;
    foreach ($yrs as $y) {
        $g = array_values(array_filter($fx, fn($r) => $r['yr'] === $y));
        $a = qm_stat($col($g, 1)); $b = qm_stat($col($g, 20));
        if (!$a['n']) continue;
        $cnt++; if ($a['mean'] > 0) $pos++;
        say(sprintf('  %-6d %8s %9.2f%% %9.2f%% %9.1f%% %9.2f%%', $y, number_format($a['n']),
            $a['mean'], $a['med'], $a['win'], $b['med'] ?? 0));
    }
    say(sprintf('  ★1일 보유 평균이 «양(+)»인 해: %d / %d', $pos, $cnt));

    say('');
    say('  ⛔거래비용 미반영 — 왕복 0.2%(세금+수수료)를 «건별로» 빼야 실제 값이 된다.');
    say('  ⛔시장 대비가 아니다 — 20일 줄은 시장 표류만큼 부풀어 있다(짧은 보유는 영향이 작다).');
    say('  통계적 사실만 적는다. 투자 판단·매매 규칙은 여기서 만들지 않는다.');
    break;
}

// ══════════════════════════════════════════════════════════════════════════
case 'status':
default: {
    say('급등주 분봉 아카이브 현황  (' . date('Y-m-d H:i:s') . ')');
    foreach (['qm_event', 'qm_task', 'qm_bar', 'qm_feat'] as $t) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            $mb = $pdo->query("SELECT ROUND((data_length+index_length)/1024/1024,1)
                                 FROM information_schema.tables
                                WHERE table_schema=DATABASE() AND table_name='{$t}'")->fetchColumn();
            say(sprintf('  %-10s %12s행 %8s MB', $t, number_format($n), $mb));
        } catch (Throwable $e) { say(sprintf('  %-10s ★없음 (job=schema 로 만든다)', $t)); }
    }
    try {
        $r = $pdo->query("SELECT state, COUNT(*) n FROM qm_task GROUP BY state ORDER BY state")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);
        say('  qm_task 상태(0대기 1진행 2완료 9포기): ' . json_encode($r));
        $r = $pdo->query("SELECT MIN(d) mn, MAX(d) mx FROM qm_event")->fetch(PDO::FETCH_ASSOC);
        if ($r['mn']) say('  이벤트 구간: ' . $r['mn'] . ' ~ ' . $r['mx']);
    } catch (Throwable $e) {}
    $kw = new Kiwoom($pdo);
    say('  키움 인증키: ' . ($kw->hasKey() ? '있음' : '★없음'));
    break;
}
}

/* bg 로 돌지 않았으면 안에서 곧바로 되돌아온다 — 조건 없이 부른다(dt_min 과 같은 꼴) */
cron_bg_finish();
?>
