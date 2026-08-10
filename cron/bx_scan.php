<?php
/**
 * cron/bx_scan.php — 「박스 상향돌파」 패턴 적재 (2026-08-09 신설 · 같은 날 전면 개편)
 *
 * ══ 무엇을 하나 ═══════════════════════════════════════════════════════════
 * 장 마감 뒤 그 날의 <b>최고 거래대금 신호</b>를 훑어 「박스 상향돌파」 점수를 매기고,
 * 컷을 넘은 것을 `bx_cand` 에 <b>매일 쌓는다</b>. 이어서 ①사후 결과 판정 ②1분봉 수집
 * ③새 후보 알림까지 한 잡에서 끝낸다. 시세 API 는 <b>분봉에만</b> 쓴다(나머지는 `krx_amt`).
 *
 * ══ 이 표가 담는 것은 두 가지다 (`src`) ═══════════════════════════════════
 *   'pick' — 사용자가 패턴분석(불꽃형) 갤러리에서 <b>직접 고른 365건</b>. 이 패턴의 «정의 원본».
 *   'auto' — 그 365건을 되맞춘 점수로 <b>매일 자동으로 뽑은 것</b>.
 *
 * ★★<b>둘을 화면에서 반드시 갈라 보여 준다.</b> 'pick' 은 «고른 것»이라 승률이 55.9% 인데
 *   'auto' 는 그렇지 않다(8년 검정에서 기준선과 사실상 같다 — `stock/lib/boxbrk.php` 머리말).
 *   섞어서 결과를 세면 <b>「이 패턴 승률 좋네」로 잘못 읽힌다</b>. `src` 가 그 문지기다.
 *
 * ══ ★결과 칸을 «담는다» (2026-08-09 사용자 지시로 방침이 바뀌었다) ══════════
 * 처음 이 표를 만들 때는 결과 칸을 <b>두지 않았다</b> — 「결과를 보고 담으면 표본이 오염된다」는
 * 이유였다(그 실패가 실제로 있었다). 사용자가 그 사실을 듣고 <b>패턴분석 화면 하나로 합치라</b>고
 * 정했으므로 결과를 담는다. 대신 오염 방지는 <b>다른 자리</b>가 맡는다:
 *   - 오늘 뜬 것은 사후 5거래일이 안 차서 <b>자연히 「사후 미완」</b>이다 → 그 날 보고 담으면 결과를 못 본다
 *   - 나중에 갈라 읽을 자가 이미 있다 — `chart_fav_item.made_at` 과 신호일 `d` 의 간격
 *
 * ══ 언제 도나 ═════════════════════════════════════════════════════════════
 * 오늘 행은 <b>15:50 `dart_eod`</b> 가 `krx_amt` 에 넣는다(잠정 src='n'). 그래서 16:20 이다.
 * ★앞으로 당기면 매일 「그 날 봉이 없다」로 조용히 0건이 된다.
 * ★다음 날 13:05 KRX 확정값이 잠정치를 덮으므로 <b>직전 거래일도 다시 잰다</b>.
 *
 * job
 *   schema    표 생성 (멱등)
 *   migrate   qm_flame 의 «관심차트에 담긴 것»을 src='pick' 으로 옮긴다 (한 번만)
 *   daily     하루치 적재 → 결과 판정 → 분봉 수집 → 알림   ← 크론이 부르는 것
 *   backfill  구간 적재 (from= to=) · 알림 없음
 *   outcome   결과 판정만 (사후가 찬 것을 갱신)
 *   minfill   1분봉 수집만 (mincalls= 로 콜 예산)
 *   alert     알림만 (dry=1 이면 미리보기)
 *   bt8y      8년 백테스트 (측정 전용 — 표를 건드리지 않는다 · 검증 탭 ⑨절의 원천)
 *   status    현황
 *
 * SSH 실행 예
 *   php cron/bx_scan.php job=migrate
 *   php cron/bx_scan.php job=backfill from=2025-08-01
 *   php cron/bx_scan.php job=daily
 *   php cron/bx_scan.php job=minfill mincalls=300
 */
require_once __DIR__ . '/_boot.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/boxbrk.php';

$CLI = (PHP_SAPI === 'cli');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-bx';
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
/* ★1GB — boxbrk_scan() 이 후보 종목의 <b>이력 전체</b>를 끌어온다(계단을 학습과 같은 자로 세려고). */
@ini_set('memory_limit', '1024M');

/** 브래킷 — 패턴분석(불꽃형)이 쓰던 것과 <b>같은 자</b>다. 바꾸면 옛 행과 견줄 수 없다. */
const BX_TP = 15.0, BX_SL = 10.0, BX_DAYS = 5;
/** 1분봉 창 — 신호일 앞 5거래일 / 뒤 10거래일 */
const BX_MIN_BACK = 5, BX_MIN_FWD = 10;
/* ★「한 배치에서 받은 봉인가」의 문턱 — 이보다 넓게 흩어져 있으면 수정주가 기준이 섞였을 수 있어
 *   「이미 있는 봉」으로 완료 처리하지 않고 구간을 통째로 다시 받는다 (bx_min_adopt) */
const BX_MIN_BATCH_SEC = 21600;   // 6시간

function bx_say(string $s): void { echo $s . "\n"; @ob_flush(); @flush(); }

// ══ 표 ═════════════════════════════════════════════════════════════════
function bx_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS bx_cand (
        code       CHAR(6)  NOT NULL,
        d          DATE     NOT NULL,
        src        CHAR(4)  NOT NULL DEFAULT 'auto' COMMENT 'pick 직접 고른 기준표본 · auto 매일 자동',
        name       VARCHAR(64) NOT NULL DEFAULT '',
        mkt        CHAR(1)  NOT NULL DEFAULT '',
        close_prc  INT UNSIGNED NOT NULL,
        prev_prc   INT UNSIGNED NOT NULL,
        chg_pct    DECIMAL(6,2) NOT NULL,
        amt        BIGINT UNSIGNED NOT NULL,
        amt_mult   DECIMAL(8,3) NOT NULL COMMENT '20일 평균 거래대금 대비 — 점수엔 안 쓰고 배지로만',
        mktcap     BIGINT UNSIGNED NULL,
        is_flame   TINYINT NOT NULL DEFAULT 0 COMMENT '20평비 20배↑ = 불꽃형 (유형 배지)',
        z          DECIMAL(8,4) NULL COMMENT '박스 상향돌파 점수(로그오즈)',
        prob       DECIMAL(5,4) NULL COMMENT '「그 날이었다면 담으셨을 확률」',
        grade      CHAR(1)  NULL COMMENT 'A 상위5% · B 상위10% · C 상위25%',
        f_vola20     DECIMAL(8,3) NULL,
        f_brk_hi120  DECIMAL(8,3) NULL,
        f_dyhigh     DECIMAL(8,3) NULL,
        f_pos60      DECIMAL(8,3) NULL,
        f_clopos     DECIMAL(8,3) NULL,
        f_brk_hi60   DECIMAL(8,3) NULL,
        f_steps_above SMALLINT NULL,
        f_w60        DECIMAL(8,4) NULL,
        f_mom60      DECIMAL(8,3) NULL,
        f_upper_w    DECIMAL(8,3) NULL,
        m_hi60   INT UNSIGNED NULL, m_lo60 INT UNSIGNED NULL,
        m_hi120  INT UNSIGNED NULL, m_hi250 INT UNSIGNED NULL,
        m_near_above INT UNSIGNED NULL, m_box_gap SMALLINT NULL,
        m_box_n SMALLINT NULL COMMENT '이전 박스 총 개수(10억↑) — 「차트에 계단 몇 벌」 = 1 + LEAST(3, m_box_n)',
        m_box_n100 SMALLINT NULL COMMENT '그중 100억↑ — 「이전에 100억 박스가 있나」 게이트',
        m_prev_h INT UNSIGNED NULL COMMENT '가장 최근 이전 박스의 고가',
        m_prev_l INT UNSIGNED NULL COMMENT '가장 최근 이전 박스의 저가',
        m_oc_pct DECIMAL(8,2) NULL COMMENT '시가→종가 (%%) — 등락(전일 종가 대비)과 다른 자',
        m_gap_pct DECIMAL(8,2) NULL COMMENT '현재 박스 저가가 기존 박스 고가보다 몇 %% 위인가 (음수=박스 안)',
        brk_kind CHAR(3) NULL COMMENT 'tp 익절 · sl 손절 · non 미도달 · amb 모호 (NULL = 아직/불가)',
        brk_ret  DECIMAL(6,2) NULL,
        brk_tp   DECIMAL(5,1) NULL, brk_sl DECIMAL(5,1) NULL,
        brk_src  CHAR(1) NOT NULL DEFAULT 'd' COMMENT 'd 일봉 · m 분봉이 가름',
        n_post   TINYINT NOT NULL DEFAULT 0 COMMENT '사후 며칠이 찼나 (BX_DAYS 미만이면 「사후 미완」)',
        f_max5 DECIMAL(6,2) NULL, f_min5 DECIMAL(6,2) NULL, f_d5 DECIMAL(6,2) NULL,
        f_max5_day TINYINT NULL, f_min5_day TINYINT NULL,
        q_ohlc TINYINT NOT NULL DEFAULT 0 COMMENT '진짜 결측이 끼어 판정 불가',
        q_halt TINYINT NOT NULL DEFAULT 0 COMMENT '사후 창에 거래정지일이 있어 «건너뛰고» 판정',
        has_min   TINYINT NOT NULL DEFAULT 0 COMMENT 'qm_bar 에 봉이 실제로 있나',
        min_stage TINYINT NOT NULL DEFAULT 0 COMMENT '0 없음 · 1 신호일까지 받음 · 2 사후까지 완료',
        src_prov  TINYINT NOT NULL DEFAULT 0 COMMENT '1=그 날 krx_amt 가 잠정치(src=n)였다',
        made_at   DATETIME NOT NULL,
        PRIMARY KEY (code, d),
        KEY ix_d (d, z), KEY ix_z (z), KEY ix_src (src, d), KEY ix_stage (min_stage, d)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      COMMENT='박스 상향돌파 — pick(직접 고른 기준표본) + auto(매일 자동). 둘을 화면에서 갈라 본다'");

    /* ★표가 이미 있으면 CREATE IF NOT EXISTS 는 건너뛴다 — 컬럼 추가는 따로 (급등주 잡의 교훈) */
    foreach ([
        "src CHAR(4) NOT NULL DEFAULT 'auto'",
        "brk_kind CHAR(3) NULL", "brk_ret DECIMAL(6,2) NULL",
        "brk_tp DECIMAL(5,1) NULL", "brk_sl DECIMAL(5,1) NULL",
        "brk_src CHAR(1) NOT NULL DEFAULT 'd'", "n_post TINYINT NOT NULL DEFAULT 0",
        "f_max5 DECIMAL(6,2) NULL", "f_min5 DECIMAL(6,2) NULL", "f_d5 DECIMAL(6,2) NULL",
        "f_max5_day TINYINT NULL", "f_min5_day TINYINT NULL",
        "q_ohlc TINYINT NOT NULL DEFAULT 0", "q_halt TINYINT NOT NULL DEFAULT 0",
        "has_min TINYINT NOT NULL DEFAULT 0", "min_stage TINYINT NOT NULL DEFAULT 0",
        "m_box_n SMALLINT NULL", "m_box_n100 SMALLINT NULL",
        "m_prev_h INT UNSIGNED NULL", "m_gap_pct DECIMAL(8,2) NULL",
        "m_prev_l INT UNSIGNED NULL", "m_oc_pct DECIMAL(8,2) NULL",
    ] as $col) {
        try { $pdo->exec("ALTER TABLE bx_cand ADD COLUMN IF NOT EXISTS {$col}"); } catch (Throwable $e) {}
    }
    /* 점수는 «pick» 에서 못 낼 수 있어(앞 이력 부족) NULL 을 허용한다 */
    foreach (['z DECIMAL(8,4) NULL', 'prob DECIMAL(5,4) NULL', 'grade CHAR(1) NULL'] as $col) {
        try { $pdo->exec("ALTER TABLE bx_cand MODIFY COLUMN {$col}"); } catch (Throwable $e) {}
    }
}

/**
 * ★<b>게이트 판정 단일본</b> — 그 봉이 「박스 상향돌파」의 다섯 조건에 걸리나.
 *
 * ★★한 함수로 모은 이유(2026-08-09에 값을 치렀다): `job=boxn` 과 `job=purgepick` 이 각자
 *   조건을 적고 있었는데, 조건을 둘 더 넣으면서 <b>purgepick 만 옛 두 개를 보고 있었다</b>.
 *   그러면 「지웠다」는데 안 맞는 것이 남는다. 판정은 여기 하나뿐이고
 *   `boxbrk_scan()` 도 <b>같은 순서·같은 조건</b>을 쓴다(그쪽은 애초에 안 담는 쪽).
 *
 * @return ?string 걸린 사유 키 (null 이면 통과) — 'nobox'|'far'|'lower'|'oc'
 */
function bx_gate_fail(array $meta): ?string
{
    if ($meta['boxN100'] < BoxBrk::MIN_PRIOR_BOX)                       return 'nobox';
    if ($meta['gapPct'] === null
        || $meta['gapPct'] > BoxBrk::BOX_GAP_MAX * 100)                 return 'far';
    if (!boxbrk_higher_box($meta))                                      return 'lower';
    if ($meta['ocPct'] === null || $meta['ocPct'] < BoxBrk::OC_MIN)     return 'oc';
    return null;
}

/** 사유 키 → 사람 말 */
function bx_gate_label(string $k): string
{
    return [
        'nobox' => '이전 100억↑ 박스 없음',
        'far'   => '기존 박스에서 +' . round(BoxBrk::BOX_GAP_MAX * 100) . '% 넘게 뜸',
        'lower' => '신규 박스가 직전 ' . BoxBrk::ABOVE_LAST_N_BOX . '개 박스보다 «아래»',
        'oc'    => '시가→종가 < ' . rtrim(rtrim(sprintf('%.1f', BoxBrk::OC_MIN), '0'), '.') . '%',
    ][$k] ?? $k;
}

/** 가장 최근 거래일 (krx_amt 가 판정한다 — 휴장일 표를 새로 만들지 않는다) */
function bx_last_day(PDO $pdo): ?string
{
    return $pdo->query("SELECT MAX(d) FROM krx_amt")->fetchColumn() ?: null;
}

// ══ ① 적재 ═════════════════════════════════════════════════════════════
/** 하루치 적재 — 멱등. ★'pick' 행은 건드리지 않는다(기준 표본은 자동 판정의 대상이 아니다). */
function bx_fill_day(PDO $pdo, string $d, bool $verbose = true): array
{
    $t0 = microtime(true);
    $cands = boxbrk_scan($pdo, $d);
    $prov = (int)$pdo->query("SELECT COUNT(*) FROM krx_amt WHERE d = " . $pdo->quote($d)
                            . " AND src = 'n'")->fetchColumn() > 0 ? 1 : 0;

    /* ★★분봉 진행 상태를 «건져 둔다» — 아래에서 그 날 auto 를 통째로 갈아 끼우는데,
     *   그냥 지우면 min_stage/has_min 이 0 으로 돌아가 <b>이미 받아 둔 봉을 다시 받는다</b>
     *   (행당 ~8콜 · 재백필 한 번에 수천 콜이 날아간다). 봉 자체는 `qm_bar` 에 그대로 있다. */
    $keepMin = [];
    $km = $pdo->prepare("SELECT code, min_stage, has_min FROM bx_cand WHERE d = ? AND src = 'auto'");
    $km->execute([$d]);
    foreach ($km->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $keepMin[$r['code']] = [(int)$r['min_stage'], (int)$r['has_min']];
    }

    /* ★그 날의 «auto» 만 갈아 끼운다 — 확정값이 덮이면 컷 아래로 내려간 것이 남으면 안 된다.
     *   'pick' 은 사용자가 고른 사실이라 다시 계산해서 지우면 안 된다. */
    $pdo->prepare("DELETE FROM bx_cand WHERE d = ? AND src = 'auto'")->execute([$d]);

    $mkt = [];
    foreach ($pdo->query("SELECT code, mkt FROM krx_amt WHERE d = " . $pdo->quote($d))
                 ->fetchAll(PDO::FETCH_ASSOC) as $r) $mkt[$r['code']] = $r['mkt'];
    $have = [];
    $st = $pdo->prepare("SELECT code FROM bx_cand WHERE d = ? AND src = 'pick'");
    $st->execute([$d]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $have[$c] = 1;

    $ins = $pdo->prepare("INSERT INTO bx_cand
        (code,d,src,name,mkt,close_prc,prev_prc,chg_pct,amt,amt_mult,mktcap,is_flame,z,prob,grade,
         f_vola20,f_brk_hi120,f_dyhigh,f_pos60,f_clopos,f_brk_hi60,f_steps_above,f_w60,f_mom60,f_upper_w,
         m_hi60,m_lo60,m_hi120,m_hi250,m_near_above,m_box_gap,m_box_n,m_box_n100,
         m_prev_h,m_prev_l,m_gap_pct,m_oc_pct,
         min_stage,has_min,brk_tp,brk_sl,src_prov,made_at)
        VALUES (?,?,'auto',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

    $n = 0; $byGrade = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($cands as $c) {
        if (isset($have[$c['code']])) continue;      // 이미 pick 으로 있는 (종목,날짜)
        $x = $c['x']; $m = $c['meta'];
        $ins->execute([
            $c['code'], $d, mb_substr($c['name'], 0, 64), (string)($mkt[$c['code']] ?? ''),
            (int)round($m['close']), (int)round($m['prev']), round($m['chg'], 2),
            (int)round($m['amt']), round($m['mult'], 3),
            $m['mktcap'] > 0 ? (int)round($m['mktcap']) : null,
            $m['mult'] >= 20 ? 1 : 0,
            round($c['z'], 4), round($c['prob'], 4), $c['grade'],
            round($x['vola20'], 3), round($x['brkHi120'], 3), round($x['dYHigh'], 3),
            round($x['posPrev60'], 3), round($x['clopos'], 3), round($x['brkHi60'], 3),
            (int)$x['stepsAbove'], round($x['lw60'], 4), round($x['mom60'], 3), round($x['upperW'], 3),
            (int)round($m['hi60']), (int)round($m['lo60']), (int)round($m['hi120']), (int)round($m['hi250']),
            $m['nearAbove'] !== null ? (int)round($m['nearAbove']) : null,
            $m['boxGap'], $m['boxN'], $m['boxN100'],
            $m['prevH'] !== null ? (int)round($m['prevH']) : null,
            $m['prevL'] !== null ? (int)round($m['prevL']) : null,
            $m['gapPct'] !== null ? round($m['gapPct'], 2) : null,
            $m['ocPct']  !== null ? round($m['ocPct'], 2)  : null,
            $keepMin[$c['code']][0] ?? 0, $keepMin[$c['code']][1] ?? 0,   // 분봉 진행 되살리기
            BX_TP, BX_SL, $prov,
        ]);
        $n++; $byGrade[$c['grade']] = ($byGrade[$c['grade']] ?? 0) + 1;
    }
    if ($verbose) {
        bx_say(sprintf('  %s — 후보 %d건 (A %d · B %d · C %d)%s · %.1f초',
            $d, $n, $byGrade['A'], $byGrade['B'], $byGrade['C'],
            $prov ? ' ★잠정치' : '', microtime(true) - $t0));
    }
    return ['n' => $n, 'grade' => $byGrade, 'prov' => $prov];
}

// ══ ② 결과 판정 ═════════════════════════════════════════════════════════
/**
 * 신호일 <b>종가</b>에 사서 BX_DAYS 안에 +BX_TP 익절 / −BX_SL 손절. 안 닿으면 마지막 거래일 종가.
 * ★패턴분석(불꽃형)의 `flamefill` 과 <b>글자 그대로 같은 규칙</b>이다 — 옛 행과 견줄 수 있어야 한다.
 *   거래정지일은 «건너뛰고»(그 날은 체결 불가) 진짜 결측이 끼면 «판정하지 않는다»(qm_day_kind).
 */
function bx_calc_outcome(array $s, int $i): array
{
    $base = (float)$s[$i]['c'];
    $out = ['brk_kind' => null, 'brk_ret' => null, 'n_post' => 0,
            'q_halt' => 0, 'q_ohlc' => 0,
            'f_max5' => null, 'f_min5' => null, 'f_d5' => null,
            'f_max5_day' => null, 'f_min5_day' => null];
    if ($base <= 0) return $out;

    $kind = null; $ret = null; $lastOk = null; $nOk = 0;
    $mx = null; $mn = null; $mxD = null; $mnD = null;
    for ($j = 1; $j <= BX_DAYS; $j++) {
        if (!isset($s[$i + $j])) break;                 // 아직 안 찼다 — 「사후 미완」
        $p = $s[$i + $j];
        $dk = boxbrk_day_kind($p);
        if ($dk === 'gap')  { $out['q_ohlc'] = 1; return $out; }   // 판정 불가
        if ($dk === 'halt') { $out['q_halt'] = 1; $nOk++; continue; }
        $nOk++; $lastOk = $p;

        $hp = ((float)$p['h'] / $base - 1) * 100;
        $lp = ((float)$p['l'] / $base - 1) * 100;
        if ($mx === null || $hp > $mx) { $mx = $hp; $mxD = $j; }
        if ($mn === null || $lp < $mn) { $mn = $lp; $mnD = $j; }

        if ($kind === null) {
            $op = ((float)$p['o'] / $base - 1) * 100;
            /* ①갭 — 브래킷은 갭을 못 막는다. 시가가 이미 넘어섰으면 «그 시가»가 체결가다. */
            if      ($op >= BX_TP)  { $kind = 'tp';  $ret = $op; }
            elseif  ($op <= -BX_SL) { $kind = 'sl';  $ret = $op; }
            /* ②같은 날 둘 다 — 일봉은 그 날 «안의 순서»를 모른다 */
            elseif  ($hp >= BX_TP && $lp <= -BX_SL) { $kind = 'amb'; $ret = -BX_SL; }
            elseif  ($hp >= BX_TP)  { $kind = 'tp';  $ret = BX_TP; }
            elseif  ($lp <= -BX_SL) { $kind = 'sl';  $ret = -BX_SL; }
        }
    }
    $out['n_post'] = $nOk;
    $out['f_max5'] = $mx !== null ? round($mx, 2) : null;
    $out['f_min5'] = $mn !== null ? round($mn, 2) : null;
    $out['f_max5_day'] = $mxD; $out['f_min5_day'] = $mnD;
    if ($lastOk) $out['f_d5'] = round(((float)$lastOk['c'] / $base - 1) * 100, 2);

    if ($kind !== null)         { $out['brk_kind'] = $kind; $out['brk_ret'] = round($ret, 2); }
    elseif ($nOk >= BX_DAYS)    { $out['brk_kind'] = 'non';
                                  $out['brk_ret'] = $lastOk ? $out['f_d5'] : null; }
    /* 그 밖 = 아직 사후가 안 찼다 → brk_kind NULL · n_post 로 「사후 미완」을 판정한다 */
    return $out;
}

/** 사후가 아직 안 찬 행(n_post < BX_DAYS)만 다시 잰다. ★찬 것은 안 건드린다(분봉 재판정을 지키려고). */
function bx_fill_outcome(PDO $pdo, bool $verbose = true): int
{
    $rows = $pdo->query("SELECT code, d FROM bx_cand
                          WHERE n_post < " . BX_DAYS . " AND q_ohlc = 0
                          ORDER BY d DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { if ($verbose) bx_say('  결과 판정 — 갱신할 행 없음'); return 0; }

    $byCode = [];
    foreach ($rows as $r) $byCode[$r['code']][] = $r['d'];
    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt FROM krx_amt WHERE code=? AND c>0 AND d>=? ORDER BY d");
    $upd = $pdo->prepare("UPDATE bx_cand SET brk_kind=?, brk_ret=?, n_post=?, q_halt=?, q_ohlc=?,
                            f_max5=?, f_min5=?, f_d5=?, f_max5_day=?, f_min5_day=?,
                            brk_tp=?, brk_sl=? WHERE code=? AND d=?");
    $n = 0;
    foreach ($byCode as $code => $ds) {
        $from = date('Y-m-d', strtotime(min($ds) . ' -3 day'));
        $sel->execute([$code, $from]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $pos = [];
        foreach ($s as $k => $b) $pos[$b['d']] = $k;
        foreach ($ds as $d) {
            if (!isset($pos[$d])) continue;
            $o = bx_calc_outcome($s, $pos[$d]);
            $upd->execute([$o['brk_kind'], $o['brk_ret'], $o['n_post'], $o['q_halt'], $o['q_ohlc'],
                           $o['f_max5'], $o['f_min5'], $o['f_d5'], $o['f_max5_day'], $o['f_min5_day'],
                           BX_TP, BX_SL, $code, $d]);
            $n++;
        }
    }
    if ($verbose) bx_say('  결과 판정 — ' . $n . '건 갱신 (사후가 찬 것은 건드리지 않는다)');
    return $n;
}

// ══ ③ 1분봉 ════════════════════════════════════════════════════════════
/**
 * 새 신호의 1분봉을 `qm_bar` 에 받아 둔다 — 카드 우측 패널이 읽는 그 표다.
 *
 * ★★<b>두 번에 나눠 받는다</b>(2026-08-09 · 콜 예산 때문에 고른 방식).
 *   1차(stage 1) 신호가 뜬 날 : «신호일 −5거래일 ~ 신호일» → 그 날 카드에 바로 분봉이 뜬다 (≈3콜)
 *   2차(stage 2) 사후가 찬 뒤 : «−5 ~ +10» 을 <b>통째로 다시</b> → 기준이 한 번에 통일된다 (≈7콜)
 *   ★2차가 그 구간을 지우고 다시 넣으므로 급등주 규칙 §5(부분 재수집 금지)를 지킨다.
 *     매일 하루씩 «덧붙이면» 한 종목 안에 수정주가 기준이 다른 봉이 섞인다.
 *
 * ★네이버 폴백을 쓰지 않는다(급등주 규칙 §2) — 네이버는 «가장 최근 거래일 하루치»만 주므로
 *   과거 구간엔 무용한데, 성공하면 bars>0 이 되어 <b>목표 날짜가 비었는데도 성공으로 보인다</b>.
 */
/**
 * ★<b>받기 «전»에 `qm_bar` 를 먼저 본다</b> (2026-08-10 · API 0회).
 *
 * 왜 — 급등주 아카이브(`qm_*`)가 같은 종목·같은 시기를 이미 쌓아 둔다. 그래서
 *   <b>한 콜도 안 쓰고 봉이 이미 있는 행</b>이 생기는데, `has_min` 은 「내가 받은 것」에만
 *   켜지므로 화면이 「분봉을 아직 못 받았습니다」라고 <b>거짓말</b>을 했다
 *   (실측 2026-08-10: 688건 중 634건이 그랬다). 화면은 `has_min` 으로 패널을 가르고
 *   API 는 플래그를 안 보고 `qm_bar` 를 읽으니, 플래그만 조용히 문지기 노릇을 하고 있었다.
 *   ★불꽃형 때 잡은 그 결함과 <b>같은 종류</b>다 — 「플래그는 표시용이지 판정의 문지기가 아니다」.
 *
 * 판정 — 받을 때와 <b>같은 자</b>(시장 거래일 −BX_MIN_BACK ~ +BX_MIN_FWD)를 쓴다.
 *   has_min=1   : 신호일 봉이 실제로 있다 → 카드가 바로 뜬다
 *   min_stage=2 : 구간의 «그 종목이 거래한 날»이 <b>전부</b> 있고 + <b>한 배치</b>에서 받은 것
 *
 * ★★배치가 갈리면 stage 를 <b>안</b> 올린다 — `upd_stkpc_tp=1` 은 «받는 시점» 기준 수정주가라,
 *   따로따로 받은 날들을 한 종목 안에 두면 기준이 섞인다(급등주 규칙 §5·§11).
 *   그런 행은 stage 0 으로 남겨 아래에서 구간을 <b>통째로</b> 다시 받게 둔다.
 */
function bx_min_adopt(PDO $pdo, array $days, array $idx, bool $verbose = true): array
{
    $n = ['sig' => 0, 'full' => 0];
    $rows = $pdo->query("SELECT code, d FROM bx_cand
                          WHERE min_stage < 2 OR has_min = 0")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $n;

    $last    = count($days) - 1;
    $stTrade = $pdo->prepare("SELECT d FROM krx_amt WHERE code=? AND d BETWEEN ? AND ? AND vol>0 ORDER BY d");
    $stBars  = $pdo->prepare("SELECT DATE(ts) dd, MIN(fetched_at) f0, MAX(fetched_at) f1
                                FROM qm_bar WHERE code=? AND ts>=? AND ts < ? + INTERVAL 1 DAY
                               GROUP BY DATE(ts)");
    $upHas   = $pdo->prepare("UPDATE bx_cand SET has_min=1 WHERE code=? AND d=?");
    $upFull  = $pdo->prepare("UPDATE bx_cand SET has_min=1, min_stage=2 WHERE code=? AND d=?");

    foreach ($rows as $r) {
        $code = $r['code']; $d = $r['d'];
        if (!isset($idx[$d])) continue;
        $i = $idx[$d];
        $a = $days[max(0, $i - BX_MIN_BACK)];
        $b = $days[min($last, $i + BX_MIN_FWD)];

        $stTrade->execute([$code, $a, $b]);
        $want = $stTrade->fetchAll(PDO::FETCH_COLUMN);

        $stBars->execute([$code, $a, $b]);
        $have = []; $f0 = null; $f1 = null;
        foreach ($stBars->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $have[$x['dd']] = 1;
            if ($f0 === null || $x['f0'] < $f0) $f0 = $x['f0'];
            if ($f1 === null || $x['f1'] > $f1) $f1 = $x['f1'];
        }
        if (!isset($have[$d])) continue;          // 신호일 봉이 없으면 그림이 안 된다

        $miss = 0;
        foreach ($want as $w) if (!isset($have[$w])) $miss++;
        $full  = ($i + BX_MIN_FWD) <= $last && $want && $miss === 0;
        $oneBt = $f0 !== null && (strtotime($f1) - strtotime($f0)) < BX_MIN_BATCH_SEC;

        if ($full && $oneBt) { $upFull->execute([$code, $d]); $n['full']++; }
        else                 { $upHas->execute([$code, $d]);  $n['sig']++;  }
    }
    if ($verbose && ($n['sig'] || $n['full'])) {
        bx_say(sprintf('  분봉 — 이미 있는 봉을 가져다 씀 (콜 0) : 완료 %d건 · 표시만 %d건',
            $n['full'], $n['sig']));
    }
    return $n;
}

function bx_minfill(PDO $pdo, int $budget = 120, bool $verbose = true): array
{
    $done = ['s1' => 0, 's2' => 0, 'calls' => 0, 'skip' => 0];

    /* ★거래일 목록을 먼저 만든다 — 「이미 있는 봉 가져다 쓰기」와 「받기」가 같은 자를 쓴다 */
    $days = $pdo->query("SELECT DISTINCT d FROM krx_amt WHERE vol > 0 AND d >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)
                          ORDER BY d")->fetchAll(PDO::FETCH_COLUMN);
    $idx = array_flip($days);

    /* ★키움을 잡기 «전»에 부른다 — 키가 없어도 이건 되고, 콜을 아낀다 */
    $ad = bx_min_adopt($pdo, $days, $idx, $verbose);
    $done['adopt'] = $ad['full']; $done['adopt_sig'] = $ad['sig'];

    try { $kw = new Kiwoom($pdo); }
    catch (Throwable $e) {
        if ($verbose) bx_say('  분봉 — 키움 키가 없어 건너뛴다 (' . $e->getMessage() . ')');
        return $done;
    }

    $last = bx_last_day($pdo);
    if (!$last) return $done;

    /* 1차 먼저 — «오늘 카드가 비지 않는 것»이 사후 완결보다 급하다.
     * ★사후가 <b>이미 찬</b> 행은 1차를 건너뛰고 곧장 2차로 간다 — 안 그러면 옛 행을
     *   (−5~0) 한 번, (−5~+10) 한 번 <b>두 번</b> 받아 콜이 그대로 두 배 든다. */
    $todo = $pdo->query("SELECT code, d, IF(n_post >= " . BX_DAYS . ", 2, 1) stage
                           FROM bx_cand WHERE min_stage = 0
                          ORDER BY d DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $todo = array_merge($todo, $pdo->query("
        SELECT code, d, 2 stage FROM bx_cand
         WHERE min_stage = 1 AND n_post >= " . BX_DAYS . "
         ORDER BY d DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC));

    foreach ($todo as $t) {
        if ($done['calls'] >= $budget) break;
        $code = $t['code']; $d = $t['d']; $stage = (int)$t['stage'];
        if (!isset($idx[$d])) { $done['skip']++; continue; }
        $i = $idx[$d];
        $a = $days[max(0, $i - BX_MIN_BACK)];
        $b = $days[min(count($days) - 1, $i + ($stage === 1 ? 0 : BX_MIN_FWD))];

        try {
            /* ★base_dt 를 반드시 넣는다(급등주 규칙 §3) — 없으면 «오늘»부터 되짚어 콜이 네 배 든다 */
            $r = $kw->minute($code, 1, '', 8, $b);
            $done['calls'] += (int)($r['calls'] ?? 1);
            $rows = array_filter($r['rows'] ?? [], fn($x) => substr($x['t'], 0, 10) >= $a
                                                         && substr($x['t'], 0, 10) <= $b);
            if (!$rows) { $done['skip']++; continue; }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM qm_bar WHERE code=? AND ts BETWEEN ? AND ?")
                ->execute([$code, $a . ' 00:00:00', $b . ' 23:59:59']);
            $ins = $pdo->prepare("INSERT INTO qm_bar (code,ts,o,h,l,c,v,fetched_at)
                                  VALUES (?,?,?,?,?,?,?,NOW())
                                  ON DUPLICATE KEY UPDATE o=VALUES(o),h=VALUES(h),l=VALUES(l),
                                        c=VALUES(c),v=VALUES(v),fetched_at=VALUES(fetched_at)");
            foreach ($rows as $bar) {
                $ins->execute([$code, $bar['t'] . ':00', (int)round($bar['o']), (int)round($bar['h']),
                               (int)round($bar['l']), (int)round($bar['c']), (int)$bar['v']]);
            }
            $pdo->prepare("UPDATE bx_cand SET min_stage=?, has_min=1 WHERE code=? AND d=?")
                ->execute([$stage, $code, $d]);
            $pdo->commit();
            $done['s' . $stage]++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $done['skip']++;
            if ($verbose) bx_say('    분봉 실패 ' . $code . ' ' . $d . ': ' . mb_substr($e->getMessage(), 0, 90));
        }
    }
    if ($verbose) {
        bx_say(sprintf('  분봉 — 1차 %d건 · 2차 %d건 · 콜 %d · 못 받음 %d',
            $done['s1'], $done['s2'], $done['calls'], $done['skip']));
    }
    return $done;
}

// ══ ④ 알림 ═════════════════════════════════════════════════════════════
/**
 * 새로 뜬 후보만 알린다. 발송·중복방지는 <b>`stock/lib/alert.php` 단일본</b>(`pf_alert_log`).
 * ★0건이면 안 보낸다 · 중복키는 (종목, 날짜) 이고 등급을 넣지 않는다 · 실패는 삼킨다.
 */
function bx_alert(PDO $pdo, array $dates, string $min = 'B', bool $dry = false): array
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/alert.php';
    pf_alert_ensure($pdo);

    $rank  = ['A' => 3, 'B' => 2, 'C' => 1];
    $floor = $rank[$min] ?? 2;
    $keep  = array_keys(array_filter($rank, fn($v) => $v >= $floor));
    if (!$dates || !$keep) return [];

    $inD = implode(',', array_fill(0, count($dates), '?'));
    $inG = implode(',', array_fill(0, count($keep), '?'));
    $st = $pdo->prepare("SELECT code, d, name, grade, prob, close_prc, chg_pct, amt, is_flame
                           FROM bx_cand WHERE d IN ($inD) AND grade IN ($inG) AND src = 'auto'
                          ORDER BY d DESC, z DESC");
    $st->execute(array_merge($dates, $keep));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    $today = $dates[0];
    $lines = [];
    foreach ($rows as $r) {
        /* ★등급을 키에 넣지 않는다 — 확정값에 B→A 로 올라간 것을 다시 쏘면 두 번 온다 */
        if (!$dry && !pf_alert_new($pdo, 'bx', $r['code'] . '|' . $r['d'])) continue;
        $lines[] = sprintf('%s %d%% %s(%s) %s %+.1f%% · %s억%s%s',
            $r['grade'], (int)round((float)$r['prob'] * 100),
            (string)$r['name'], $r['code'],
            number_format((int)$r['close_prc']), (float)$r['chg_pct'],
            number_format((int)$r['amt'] / 1e8),
            ((int)$r['is_flame'] ? ' ·불꽃형' : ''),
            ($r['d'] === $today ? '' : ' ·' . substr((string)$r['d'], 5) . '(재판정)'));
    }
    if (!$lines) return [];

    /* ★★자르기를 «여기서» 한다 — pf_alert_send() 는 10줄에서 자르므로, 이 문구를 그냥 끝에
     *   붙이면 후보가 많은 날에 <b>조용히 사라진다</b>(가장 필요한 날에 없어진다). */
    $MAX = 8;
    $body = array_slice($lines, 0, $MAX);
    if (count($lines) > $MAX) $body[] = '… 외 ' . (count($lines) - $MAX) . '건';
    $body[] = '※성과 우위 미검증 — 판단 소집용입니다(8년 검정에서 기준선과 사실상 같음).';
    $title = '📦 박스 상향돌파 ' . count($lines) . '건';
    $url   = 'https://economist.kr/stock/index.php?mode=boxbrk&d=' . urlencode($today);
    if ($dry) {
        bx_say('  [미리보기 · 보내지 않음] ' . $title);
        foreach ($body as $b) bx_say('    | ' . $b);
        bx_say('    링크: ' . $url);
        return $lines;
    }
    pf_alert_send($title, $body, $url);
    return $lines;
}

// ══ 디스패치 ═══════════════════════════════════════════════════════════
$job = (string)($_GET['job'] ?? 'daily');
bx_say('bx_scan · job=' . $job . ' · ' . date('Y-m-d H:i:s'));
bx_schema($pdo);

switch ($job) {

case 'schema':
    bx_say('표 bx_cand 준비 완료 (멱등)');
    break;

/* ── qm_flame 의 «관심차트에 담긴 것»을 기준 표본으로 옮긴다 (한 번만) ── */
case 'migrate': {
    try { $pdo->query("SELECT 1 FROM qm_flame LIMIT 1"); }
    catch (Throwable $e) { bx_say('qm_flame 이 없다 — 이미 옮겼거나 지운 뒤다'); break; }

    /* 담긴 것 = chart_fav_item (src 는 옛 'flame' 과 새 'boxbrk' 둘 다 본다) */
    $rows = $pdo->query("SELECT f.* FROM qm_flame f
                          WHERE EXISTS(SELECT 1 FROM chart_fav_item i
                                        WHERE i.src IN ('flame','boxbrk')
                                          AND i.code = f.code AND i.d = f.d)")->fetchAll(PDO::FETCH_ASSOC);
    bx_say('관심차트에 담긴 것 ' . count($rows) . '건을 src=pick 으로 옮긴다');
    if (!$rows) break;

    /* 점수·축은 여기서 «다시 계산»한다 — qm_flame 에는 없던 값이다.
     * ★pick 은 하드 배제·컷을 적용하지 않는다 — 사용자가 고른 사실이지 판정 대상이 아니다. */
    $byCode = [];
    foreach ($rows as $r) $byCode[$r['code']][] = $r;
    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt,mktcap FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $ins = $pdo->prepare("INSERT INTO bx_cand
        (code,d,src,name,mkt,close_prc,prev_prc,chg_pct,amt,amt_mult,mktcap,is_flame,z,prob,grade,
         f_vola20,f_brk_hi120,f_dyhigh,f_pos60,f_clopos,f_brk_hi60,f_steps_above,f_w60,f_mom60,f_upper_w,
         m_hi60,m_lo60,m_hi120,m_hi250,m_near_above,m_box_gap,
         brk_kind,brk_ret,brk_tp,brk_sl,brk_src,n_post,f_max5,f_min5,f_d5,f_max5_day,f_min5_day,
         q_ohlc,q_halt,has_min,min_stage,made_at)
        VALUES (?,?,'pick',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE src='pick'");
    $n = 0; $noScore = 0;
    foreach ($byCode as $code => $rs) {
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $pos = []; foreach ($s as $k => $b) $pos[$b['d']] = $k;
        $boxes = $s ? boxbrk_boxes($s) : [];
        foreach ($rs as $r) {
            $x = null; $meta = null;
            if (isset($pos[$r['d']])) {
                $f = boxbrk_feat($s, $pos[$r['d']], $boxes);
                if ($f) { $x = $f['x']; $meta = $f['meta']; }
            }
            if (!$x) $noScore++;
            $z = $x ? boxbrk_score($x) : null;
            $g = $z !== null ? boxbrk_grade($z)[0] : null;
            /* 분봉이 실제로 있나 — qm_flame.has_min 을 믿지 않고 qm_bar 를 본다(그 플래그가 틀렸던 적이 있다) */
            $hm = (int)$pdo->query("SELECT EXISTS(SELECT 1 FROM qm_bar WHERE code="
                    . $pdo->quote($code) . " AND ts BETWEEN " . $pdo->quote($r['d'] . ' 00:00:00')
                    . " AND " . $pdo->quote($r['d'] . ' 23:59:59') . ")")->fetchColumn();
            $ins->execute([
                $code, $r['d'], mb_substr((string)$r['name'], 0, 64), (string)$r['mkt'],
                (int)$r['close_prc'], (int)$r['prev_prc'], (float)$r['chg_pct'],
                (int)$r['amt'], (float)$r['amt_mult'],
                $meta && $meta['mktcap'] > 0 ? (int)round($meta['mktcap']) : null,
                (float)$r['amt_mult'] >= 20 ? 1 : 0,
                $z !== null ? round($z, 4) : null,
                $z !== null ? round(boxbrk_prob($z), 4) : null, $g,
                $x ? round($x['vola20'], 3) : null, $x ? round($x['brkHi120'], 3) : null,
                $x ? round($x['dYHigh'], 3) : null, $x ? round($x['posPrev60'], 3) : null,
                $x ? round($x['clopos'], 3) : null, $x ? round($x['brkHi60'], 3) : null,
                $x ? (int)$x['stepsAbove'] : null, $x ? round($x['lw60'], 4) : null,
                $x ? round($x['mom60'], 3) : null, $x ? round($x['upperW'], 3) : null,
                $meta ? (int)round($meta['hi60']) : null, $meta ? (int)round($meta['lo60']) : null,
                $meta ? (int)round($meta['hi120']) : null, $meta ? (int)round($meta['hi250']) : null,
                $meta && $meta['nearAbove'] !== null ? (int)round($meta['nearAbove']) : null,
                $meta ? $meta['boxGap'] : null,
                $r['brk_kind'], $r['brk_ret'], $r['brk_tp'], $r['brk_sl'], $r['brk_src'],
                $r['brk_kind'] !== null ? BX_DAYS : 0,
                $r['f_max5'], $r['f_min5'], $r['f_d5'], $r['f_max5_day'], $r['f_min5_day'],
                (int)$r['q_ohlc'], (int)$r['q_halt'], $hm, $hm ? 2 : 0,
            ]);
            $n++;
        }
    }
    bx_say('  옮김 ' . $n . '건 · 점수를 못 낸 것 ' . $noScore . '건(앞 이력 부족)');
    /* 관심차트의 src 를 새 화면 것으로 통일한다 — 화면이 하나가 됐으므로 */
    $u = $pdo->exec("UPDATE IGNORE chart_fav_item SET src='boxbrk' WHERE src='flame'");
    bx_say('  관심차트 src flame→boxbrk ' . (int)$u . '건');
    break;
}

case 'daily': {
    $d = (string)($_GET['d'] ?? '');
    if ($d === '') $d = (string)bx_last_day($pdo);
    if ($d === '') { bx_say('krx_amt 가 비어 있다 — 할 일 없음'); break; }
    bx_say('신호 = ①이전 ' . number_format(BoxBrk::PRIOR_BOX_AMT / 1e8) . '억↑ 박스 있음'
        . ' ②그 날 박스 신설(직전 ' . (BoxBrk::WIN - 1) . '거래일 최고 거래대금 & '
        . number_format(BoxBrk::MINAMT / 1e8) . '억↑) ③기존 박스에서 +'
        . round(BoxBrk::BOX_GAP_MAX * 100) . '% 이내 · 등락 < '
        . rtrim(rtrim(sprintf('%.1f', BoxBrk::CHG_MAX), '0'), '.') . '%'
        . '  ※점수 컷 없음(등급은 표시·알림용)');
    bx_fill_day($pdo, $d);
    $prev = $pdo->prepare("SELECT MAX(d) FROM krx_amt WHERE d < ?");
    $prev->execute([$d]);
    $y = $prev->fetchColumn();
    $dates = [$d];
    if ($y) {
        $was = (int)$pdo->query("SELECT COUNT(*) FROM bx_cand WHERE d = " . $pdo->quote($y)
                              . " AND src='auto'")->fetchColumn();
        if ($was > 0) {
            bx_say('  직전 거래일 재판정 (확정값이 덮였을 수 있다)');
            bx_fill_day($pdo, $y);
            $dates[] = (string)$y;
        }
    }

    bx_say('── 결과 판정');
    try { bx_fill_outcome($pdo); }
    catch (Throwable $e) { bx_say('  결과 판정 실패(무시): ' . $e->getMessage()); }

    bx_say('── 1분봉');
    try { bx_minfill($pdo, max(0, (int)($_GET['mincalls'] ?? 150))); }
    catch (Throwable $e) { bx_say('  분봉 실패(무시): ' . $e->getMessage()); }

    if ((string)($_GET['alert'] ?? '1') !== '0') {
        bx_say('── 새 후보 알림');
        try {
            $ag = strtoupper((string)($_GET['ag'] ?? 'B'));
            if (!in_array($ag, ['A', 'B', 'C'], true)) $ag = 'B';
            $sent = bx_alert($pdo, $dates, $ag);
            bx_say($sent ? '  ' . count($sent) . '건 발송 (등급 ' . $ag . '↑): '
                            . implode(' / ', array_slice($sent, 0, 3))
                         : '  새 후보 없음 — 발송 안 함');
        } catch (Throwable $e) {
            bx_say('  알림 실패(무시하고 계속): ' . $e->getMessage());
            try { pf_alert_fail('boxbrk', $e); } catch (Throwable $e2) {}
        }
    } else bx_say('── 알림 건너뜀 (alert=0)');
    break;
}

case 'backfill': {
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to'] ?? (string)bx_last_day($pdo));
    if ($from === '') { bx_say('from=YYYY-MM-DD 가 필요하다'); break; }
    $st = $pdo->prepare("SELECT DISTINCT d FROM krx_amt WHERE d BETWEEN ? AND ? ORDER BY d");
    $st->execute([$from, $to]);
    $days = $st->fetchAll(PDO::FETCH_COLUMN);
    bx_say('구간 ' . $from . ' ~ ' . $to . ' · 거래일 ' . count($days) . '일');
    $tot = 0; $t0 = microtime(true);
    foreach ($days as $d) { $r = bx_fill_day($pdo, $d); $tot += $r['n']; }
    bx_say(sprintf('합계 %d건 · %.0f초 · 하루 평균 %.1f건',
        $tot, microtime(true) - $t0, $tot / max(1, count($days))));
    bx_say('── 결과 판정');
    bx_fill_outcome($pdo);
    break;
}

case 'outcome':
    bx_fill_outcome($pdo);
    break;

/* ── 이전 박스 개수(m_box_n) 재계산 + 「뚫을 박스가 없는」 auto 행 정리 ──
 *   ★2026-08-09: 박스 창을 차트 지표(직전 120봉)에 맞추면서 한 번 돌려야 한다.
 *   ★`pick` 은 지우지 않는다 — 사용자가 «고른 사실»이라 내가 판정으로 지울 것이 아니다. */
case 'boxn': {
    $rows = $pdo->query("SELECT code, d, src FROM bx_cand ORDER BY code, d")->fetchAll(PDO::FETCH_ASSOC);
    $byCode = [];
    foreach ($rows as $r) $byCode[$r['code']][] = $r;
    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $upd = $pdo->prepare("UPDATE bx_cand SET m_box_n=?, m_box_n100=?, m_box_gap=?,
                            m_prev_h=?, m_prev_l=?, m_gap_pct=?, m_oc_pct=? WHERE code=? AND d=?");
    $n = 0;
    $why = ['nobox' => ['pick'=>0,'auto'=>0], 'far' => ['pick'=>0,'auto'=>0],
            'lower' => ['pick'=>0,'auto'=>0], 'oc'  => ['pick'=>0,'auto'=>0]];
    $kill = [];
    foreach ($byCode as $code => $rs) {
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$s) continue;
        $pos = []; foreach ($s as $k => $b) $pos[$b['d']] = $k;
        $boxes = boxbrk_boxes($s);
        foreach ($rs as $r) {
            if (!isset($pos[$r['d']])) continue;
            $i = $pos[$r['d']];
            $cnt = 0; $cnt100 = 0; $last = null; $pH = null; $pL = null; $hs = [];
            foreach ($boxes as $bx) {
                if ($bx['i'] >= $i) break;
                $cnt++; $last = $bx['i']; $pH = $bx['H']; $pL = $bx['L'];
                $hs[] = $bx['H'];
                if (count($hs) > BoxBrk::ABOVE_LAST_N_BOX) array_shift($hs);
                if (($bx['amt'] ?? 0) >= BoxBrk::PRIOR_BOX_AMT) $cnt100++;
            }
            $pHmax = $hs ? max($hs) : null;
            $curL = (float)$s[$i]['l']; $curH = (float)$s[$i]['h'];
            $o = (float)$s[$i]['o'];    $c2   = (float)$s[$i]['c'];
            $gap = ($pH !== null && $pH > 0) ? ($curL / $pH - 1) * 100 : null;
            $oc  = $o > 0 ? ($c2 / $o - 1) * 100 : null;
            $upd->execute([$cnt, $cnt100, $last !== null ? $i - $last : null,
                           $pH !== null ? (int)round($pH) : null,
                           $pL !== null ? (int)round($pL) : null,
                           $gap !== null ? round($gap, 2) : null,
                           $oc  !== null ? round($oc, 2)  : null, $code, $r['d']]);
            $n++;
            /* ★게이트는 `bx_gate_fail()` 하나만 본다 (그 함수 주석 — 두 곳에 적었다가 값을 치렀다) */
            $k = bx_gate_fail(['boxN100' => $cnt100, 'gapPct' => $gap, 'ocPct' => $oc,
                               'prevH' => $pH, 'prevL' => $pL, 'prevHmax' => $pHmax,
                               'curH' => $curH, 'curL' => $curL]);
            if ($k !== null) {
                $why[$k][$r['src']]++;
                if ($r['src'] === 'auto') $kill[] = [$code, $r['d']];
            }
        }
    }
    bx_say('재계산 ' . $n . '건 (박스 창 직전 ' . (BoxBrk::STEPWIN - 1)
        . '봉 · 이전박스 ' . number_format(BoxBrk::PRIOR_BOX_AMT / 1e8) . '억↑ · 갭 ≤ +'
        . round(BoxBrk::BOX_GAP_MAX * 100) . '% · 신규박스가 위 · 시가→종가 ≥ '
        . rtrim(rtrim(sprintf('%.1f', BoxBrk::OC_MIN), '0'), '.') . '%)');
    bx_say('  ①이전 100억↑ 박스 없음      — auto ' . $why['nobox']['auto'] . ' · pick ' . $why['nobox']['pick']);
    bx_say('  ②기존 박스에서 너무 멀리 뜸  — auto ' . $why['far']['auto']   . ' · pick ' . $why['far']['pick']);
    bx_say('  ③신규 박스가 «아래»로 내려섬 — auto ' . $why['lower']['auto'] . ' · pick ' . $why['lower']['pick']);
    bx_say('  ④시가→종가 < ' . rtrim(rtrim(sprintf('%.1f', BoxBrk::OC_MIN), '0'), '.')
        . '%          — auto ' . $why['oc']['auto'] . ' · pick ' . $why['oc']['pick']);
    if ($kill) {
        $del = $pdo->prepare("DELETE FROM bx_cand WHERE code=? AND d=? AND src='auto'");
        foreach ($kill as [$cd, $dd]) $del->execute([$cd, $dd]);
        bx_say('  ★auto ' . count($kill) . '건 삭제 (앞으로는 boxbrk_scan 이 안 담는다)');
    }
    bx_say('  ※pick 은 지우지 않았다 — 사용자가 «고른 사실»이라 판정으로 지울 것이 아니다');
    break;
}

case 'minfill':
    bx_minfill($pdo, max(1, (int)($_GET['mincalls'] ?? 300)));
    break;

/* ── 직접 고른 것(pick) 중 «지금 규칙에 안 맞는 것»을 지운다 (2026-08-09 사용자 지시) ──
 *
 * ★평소엔 pick 을 지우지 않는다 — 「고른 사실」이라 판정으로 지울 것이 아니다. 이 잡은
 *   <b>사용자가 명시적으로 시켰을 때만</b> 부른다(그래서 job 을 따로 뒀다).
 * ★★`chart_fav_item` 도 함께 지운다 — 안 지우면 그룹 건수는 그대로인데 화면엔 안 보여
 *   「숫자가 새는」 상태가 된다(ChartFav::groups() 가 item 을 센다).
 * ★지우기 «전»에 목록을 찍는다 — 되돌릴 원본(qm_flame)이 이미 없어서, 로그가 유일한 기록이다. */
case 'purgepick': {
    /* ★원장에서 «다시 계산»한다 — 저장 컬럼만으로는 ④(신규 박스가 위)를 못 잰다(curH/curL 이 없다).
     *   판정은 `bx_gate_fail()` 하나뿐이라 `boxn`·`boxbrk_scan` 과 같은 답이 나온다. */
    $rows = $pdo->query("SELECT code, d, name FROM bx_cand WHERE src='pick' ORDER BY code, d")
                ->fetchAll(PDO::FETCH_ASSOC);
    $byCode = [];
    foreach ($rows as $r) $byCode[$r['code']][] = $r;
    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt,mktcap FROM krx_amt WHERE code=? AND c>0 ORDER BY d");
    $kill = [];
    foreach ($byCode as $code => $rs) {
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$s) continue;
        $pos = []; foreach ($s as $k2 => $b) $pos[$b['d']] = $k2;
        $boxes = boxbrk_boxes($s);
        foreach ($rs as $r) {
            if (!isset($pos[$r['d']])) continue;
            $f = boxbrk_feat($s, $pos[$r['d']], $boxes);
            $k2 = ($f === null) ? 'nobox' : bx_gate_fail($f['meta']);
            if ($k2 !== null) $kill[] = $r + ['why' => bx_gate_label($k2)];
        }
    }
    usort($kill, fn($a, $b) => $a['d'] <=> $b['d']);
    bx_say('pick ' . count($rows) . '건 중 지금 규칙에 안 맞는 것 ' . count($kill) . '건');
    foreach ($kill as $r) {
        bx_say(sprintf('  %s %s(%s) — %s', $r['d'], (string)$r['name'], $r['code'], $r['why']));
    }
    if (!$kill) break;
    if ((string)($_GET['go'] ?? '') !== '1') {
        bx_say('★미리보기다 — 실제로 지우려면 go=1 을 붙인다');
        break;
    }
    $dc = $pdo->prepare("DELETE FROM chart_fav_item WHERE src='boxbrk' AND code=? AND d=?");
    $db = $pdo->prepare("DELETE FROM bx_cand WHERE src='pick' AND code=? AND d=?");
    $nf = 0;
    foreach ($kill as $r) { $dc->execute([$r['code'], $r['d']]); $nf += $dc->rowCount(); $db->execute([$r['code'], $r['d']]); }
    bx_say('  ★지웠다 — bx_cand ' . count($kill) . '건 · 관심차트 항목 ' . $nf . '건');
    break;
}

case 'alert': {
    $d = (string)($_GET['d'] ?? '');
    if ($d === '') $d = (string)($pdo->query("SELECT MAX(d) FROM bx_cand WHERE src='auto'")->fetchColumn() ?: '');
    if ($d === '') { bx_say('bx_cand 가 비어 있다 — 할 일 없음'); break; }
    $ag = strtoupper((string)($_GET['ag'] ?? 'B'));
    if (!in_array($ag, ['A', 'B', 'C'], true)) $ag = 'B';
    $dry = ((string)($_GET['dry'] ?? '') === '1');
    $sent = bx_alert($pdo, [$d], $ag, $dry);
    bx_say($sent ? count($sent) . '건 ' . ($dry ? '(미리보기)' : '발송') : '새 후보 없음 — 발송 안 함');
    break;
}

/* ── 8년 백테스트 — <b>측정 전용</b>. bx_cand 에 아무것도 쓰지 않는다 ──────────
 *
 * ★왜 있나(2026-08-10) — 「auto 는 8년 검정에서 기준선과 사실상 같다」는 옛 측정은
 *   조건 ③④⑤(붙어 있음·직전 3개보다 위·시가→종가 +7%)를 조이기 «전» 정의로 잰 것이다.
 *   최종 5조건 정의의 8년 성적은 이 잡이 처음 잰다. 검증 탭 ⑨절이 이 출력의 숫자를 싣는다.
 *
 * ★잣대는 qm_collect 의 `dbrk` 와 <b>글자 그대로 같다</b> — 그래야 「불꽃형 8년 표」와
 *   같은 자로 견줄 수 있다: 신호일 «종가» 매수 · 5거래일 +15/−10 브래킷 · 갭 우선 ·
 *   모호(같은 날 둘 다)는 낙관/비관 두 경계로 · 거래정지일 건너뜀 · 진짜 결측 판정 접음 ·
 *   상장주식수 5% 변동 구간 제외(액면분할 가짜 −90% 방지) · 왕복 비용 0.20% «가정».
 * ★기준선 = «최고 거래대금 신호 전체»(하한 100억 · 상한가 제외)를 <b>같은 패스</b>에서 잰다 —
 *   두 번 돌면 우주가 어긋난다(제외 규칙 하나만 달라도 부분집합이 아니게 된다). */
case 'bt8y': {
    $TP = BX_TP; $SL = BX_SL; $DAYS = BX_DAYS;
    $COST = 0.20;                                    // 왕복 비용 «가정»(%) — dbrk 와 같다
    bx_say('8년 백테스트 — 「박스 상향돌파」 최종 5조건 (측정 전용 · 표는 건드리지 않는다)');
    bx_say(sprintf('브래킷: 신호일 종가 매수 → %d거래일 +%.0f%%/−%.0f%% · 모호는 낙관/비관 두 경계 · 비용 %.2f%% 가정',
        $DAYS, $TP, $SL, $COST));
    bx_say('5조건: ①이전 ' . number_format(BoxBrk::PRIOR_BOX_AMT / 1e8) . '억↑ 박스 ②박스 신설(직전 '
        . (BoxBrk::WIN - 1) . '거래일 최고 & ' . number_format(BoxBrk::MINAMT / 1e8) . '억↑) ③기존 박스 +'
        . round(BoxBrk::BOX_GAP_MAX * 100) . '% 이내 ④직전 ' . BoxBrk::ABOVE_LAST_N_BOX
        . '개 박스보다 위 ⑤시가→종가 +' . rtrim(rtrim(sprintf('%.1f', BoxBrk::OC_MIN), '0'), '.')
        . '%↑ (하드 배제: 등락 ' . rtrim(rtrim(sprintf('%.1f', BoxBrk::CHG_MAX), '0'), '.') . '%↑)');

    $etf = [];
    try {
        foreach ($pdo->query("SELECT DISTINCT etf_code FROM all_etf_price")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $etf[$c] = 1;
        }
    } catch (Throwable $e) {}
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $names[$r['stock_code']] = $r['stock_name'];
    }
    $codes = $pdo->query("SELECT DISTINCT code FROM krx_amt ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
    $sel = $pdo->prepare("SELECT d,o,h,l,c,vol,amt,list_shrs FROM krx_amt WHERE code=? AND c>0 ORDER BY d");

    /* 신호 하나 = ['d','yr','kind','opt','pes','gap','chg','bx'(5조건 통과),'grade'] */
    $rows = []; $nGapSig = 0; $nNoDay = 0; $nBx = 0; $t0 = microtime(true);
    foreach ($codes as $ci => $code) {
        if (substr($code, -1) !== '0' || isset($etf[$code])) continue;
        $nm = (string)($names[$code] ?? '');
        if ($nm !== '' && (mb_strpos($nm, '스팩') !== false || stripos($nm, 'ETN') !== false)) continue;
        $sel->execute([$code]);
        $s = $sel->fetchAll(PDO::FETCH_ASSOC);
        $n = count($s);
        if ($n < BoxBrk::WIN + $DAYS + 2) continue;

        $boxes = null;                    // 신호가 있는 종목만 계산 (lazy)
        $dq = [];                         // 직전 (WIN-1)봉 최고 거래대금 — 단조 덱 O(n)
        for ($i = 0; $i < $n - $DAYS - 1; $i++) {
            while ($dq && $dq[0] < $i - (BoxBrk::WIN - 1)) array_shift($dq);
            $amt = (float)$s[$i]['amt'];
            $rollMax = $dq ? (float)$s[$dq[0]]['amt'] : 0.0;

            if ($i >= BoxBrk::WIN && $amt >= BoxBrk::MINAMT && $amt > $rollMax
                && boxbrk_day_kind($s[$i]) === 'ok') {
                /* 액면분할 가드 — dbrk 와 같은 자 */
                $ls = (int)$s[$i]['list_shrs']; $bad = false;
                for ($k = $i; $k <= min($n - 1, $i + $DAYS + 1); $k++) {
                    $x2 = (int)$s[$k]['list_shrs'];
                    if ($ls > 0 && $x2 > 0 && abs($x2 / $ls - 1) > 0.05) { $bad = true; break; }
                }
                $base = (float)$s[$i]['c']; $prev = (float)$s[$i - 1]['c'];
                if (!$bad && $base > 0 && $prev > 0) {
                    /* ── 브래킷 (dbrk 의 판정 그대로) ── */
                    $kind = 'non'; $opt = null; $pes = null; $gap = 0;
                    $hadGap = false; $lastOk = null;
                    for ($j = 1; $j <= $DAYS; $j++) {
                        $p = $s[$i + $j];
                        $dk = boxbrk_day_kind($p);
                        if ($dk === 'gap')  { $hadGap = true; break; }
                        if ($dk === 'halt') { continue; }
                        $lastOk = $p;
                        $op = ((float)$p['o'] / $base - 1) * 100;
                        $hp = ((float)$p['h'] / $base - 1) * 100;
                        $lp = ((float)$p['l'] / $base - 1) * 100;
                        if ($op >= $TP)  { $kind = 'tp'; $opt = $pes = $op; $gap = 1; break; }
                        if ($op <= -$SL) { $kind = 'sl'; $opt = $pes = $op; $gap = 1; break; }
                        if ($hp >= $TP && $lp <= -$SL) { $kind = 'amb'; $opt = $TP; $pes = -$SL; break; }
                        if ($hp >= $TP)  { $kind = 'tp'; $opt = $pes = $TP;  break; }
                        if ($lp <= -$SL) { $kind = 'sl'; $opt = $pes = -$SL; break; }
                    }
                    if ($hadGap) { $nGapSig++; }
                    elseif ($kind === 'non' && $lastOk === null) { $nNoDay++; }
                    else {
                        if ($kind === 'non') $opt = $pes = ((float)$lastOk['c'] / $base - 1) * 100;
                        /* ── 5조건 판정 — 게이트는 bx_gate_fail() 하나만 본다 ── */
                        $bx = 0; $grade = null;
                        if ($boxes === null) $boxes = boxbrk_boxes($s);
                        $f = boxbrk_feat($s, $i, $boxes);
                        if ($f !== null && boxbrk_hard_ok($f['x'], $f['meta'])
                            && bx_gate_fail($f['meta']) === null) {
                            $bx = 1; $nBx++;
                            $grade = boxbrk_grade(boxbrk_score($f['x']))[0];
                        }
                        $rows[] = ['d' => $s[$i]['d'], 'yr' => (int)substr($s[$i]['d'], 0, 4),
                                   'kind' => $kind, 'opt' => $opt, 'pes' => $pes, 'gap' => $gap,
                                   'chg' => ($base / $prev - 1) * 100, 'bx' => $bx, 'grade' => $grade];
                    }
                }
            }
            while ($dq && (float)$s[end($dq)]['amt'] <= $amt) array_pop($dq);
            $dq[] = $i;
        }
        if ($ci % 400 === 0) {
            bx_say(sprintf('  … %d종목 · 신호 %s · 박스돌파 %s · %.0f초',
                $ci, number_format(count($rows)), number_format($nBx), microtime(true) - $t0));
        }
    }
    bx_say(sprintf('신호 %s건 (결측 접음 %s · 창에 거래일 없음 %s) · %.0f초',
        number_format(count($rows)), number_format($nGapSig), number_format($nNoDay),
        microtime(true) - $t0));

    $stat = function (array $v): array {
        if (!$v) return ['mean' => 0.0, 'med' => 0.0];
        sort($v); $n2 = count($v);
        return ['mean' => array_sum($v) / $n2,
                'med'  => $n2 % 2 ? $v[intdiv($n2, 2)] : ($v[$n2 / 2 - 1] + $v[$n2 / 2]) / 2];
    };
    $report = function (string $title, array $g) use ($TP, $SL, $DAYS, $COST, $stat) {
        bx_say('── ' . $title . '  (n=' . number_format(count($g)) . ')');
        if (!$g) { bx_say('    표본 없음'); return; }
        $n2 = count($g);
        $c = ['tp' => 0, 'sl' => 0, 'amb' => 0, 'non' => 0];
        foreach ($g as $r) $c[$r['kind']]++;
        bx_say(sprintf('    익절 %s (%.1f%%) · 손절 %s (%.1f%%) · 모호 %s (%.1f%%) · 미도달→D+%d %s (%.1f%%)',
            number_format($c['tp']), $c['tp'] / $n2 * 100, number_format($c['sl']), $c['sl'] / $n2 * 100,
            number_format($c['amb']), $c['amb'] / $n2 * 100, $DAYS,
            number_format($c['non']), $c['non'] / $n2 * 100));
        foreach ([['낙관(모호=익절)', 'opt'], ['비관(모호=손절)', 'pes']] as [$lab, $k]) {
            $v = array_map(fn($r) => $r[$k], $g);
            $s2 = $stat($v);
            $wr = count(array_filter($v, fn($x) => $x > 0)) / $n2 * 100;
            $wc = count(array_filter($v, fn($x) => $x - $COST > 0)) / $n2 * 100;
            bx_say(sprintf('    %-16s 평균 %7.2f%%  중앙 %7.2f%%  승률 %5.1f%%  | 비용 뒤 평균 %+.2f%% · 승률 %5.1f%%',
                $lab, $s2['mean'], $s2['med'], $wr, $s2['mean'] - $COST, $wc));
        }
    };

    /* R1 기준선 — 상한가 제외(chg<29 · dbrk R2 와 같은 자). 20평비 조건은 «없다» —
     *   불꽃형 표(20배↑)가 아니라 「최고 거래대금 신호 전체」가 이 패턴의 모집단이다. */
    $baseAll = array_values(array_filter($rows, fn($r) => $r['chg'] < 29));
    $bxRows  = array_values(array_filter($rows, fn($r) => $r['bx'] === 1));
    $report('R1. 기준선 — 최고 거래대금 신호 전체 (100억↑ · 상한가 제외)', $baseAll);
    $report('R2. 박스 상향돌파 — 최종 5조건', $bxRows);

    bx_say('── R3. 등급별 (R2 안에서 · 등급=「담은 것과 닮음」 — 「오를까」가 아니다)');
    foreach (['A', 'B', 'C', 'D'] as $g2) {
        $gg = array_values(array_filter($bxRows, fn($r) => $r['grade'] === $g2));
        if (!$gg) { bx_say('    ' . $g2 . ': 표본 없음'); continue; }
        $v = array_map(fn($r) => $r['pes'], $gg);
        $s2 = $stat($v);
        $wr = count(array_filter($v, fn($x) => $x > 0)) / count($gg) * 100;
        bx_say(sprintf('    %s  n=%5s  비관 평균 %+.2f%% · 중앙 %+.2f%% · 승률 %.1f%%',
            $g2, number_format(count($gg)), $s2['mean'], $s2['med'], $wr));
    }

    bx_say('── R4. 연도별 (R2 · 비관 기준)');
    $yrs = array_values(array_unique(array_map(fn($r) => $r['yr'], $bxRows)));
    sort($yrs);
    $pos = 0; $cnt = 0;
    foreach ($yrs as $y) {
        $gg = array_values(array_filter($bxRows, fn($r) => $r['yr'] === $y));
        if (!$gg) continue;
        $v = array_map(fn($r) => $r['pes'], $gg);
        $s2 = $stat($v);
        $wr = count(array_filter($v, fn($x) => $x > 0)) / count($gg) * 100;
        $cnt++; if ($s2['mean'] - $COST > 0) $pos++;
        bx_say(sprintf('    %d  n=%5s  비관 평균 %+.2f%% · 승률 %.1f%%',
            $y, number_format(count($gg)), $s2['mean'], $wr));
    }
    bx_say(sprintf('    ★비용 뒤 평균이 «양(+)»인 해: %d / %d', $pos, $cnt));

    bx_say('── R5. 기간 2분할 (R2 · 비관 기준 — 부호가 유지되나)');
    $mid = $bxRows ? $bxRows[intdiv(count($bxRows), 2)]['d'] : '';
    usort($bxRows, fn($a, $b) => $a['d'] <=> $b['d']);
    $half = intdiv(count($bxRows), 2);
    foreach ([['전반', array_slice($bxRows, 0, $half)], ['후반', array_slice($bxRows, $half)]] as [$lab, $gg]) {
        if (!$gg) continue;
        $v = array_map(fn($r) => $r['pes'], $gg);
        $s2 = $stat($v);
        $wr = count(array_filter($v, fn($x) => $x > 0)) / count($gg) * 100;
        bx_say(sprintf('    %s  %s ~ %s  n=%s  비관 평균 %+.2f%% · 승률 %.1f%%',
            $lab, $gg[0]['d'], $gg[count($gg) - 1]['d'], number_format(count($gg)), $s2['mean'], $wr));
    }
    bx_say('  ⛔호가 잔량·슬리피지 미반영 · 통계적 사실만 적는다 — 매매 규칙은 여기서 만들지 않는다.');
    break;
}

case 'status': {
    $a = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT d) nd, MIN(d) a, MAX(d) b,
                        SUM(src='pick') pick, SUM(src='auto') auto,
                        SUM(grade='A') ga, SUM(grade='B') gb, SUM(grade='C') gc,
                        SUM(is_flame) fl, SUM(has_min) hm,
                        SUM(brk_kind='tp') tp, SUM(brk_kind='sl') sl, SUM(brk_kind='non') non,
                        SUM(brk_kind='amb') amb, SUM(brk_kind IS NULL AND q_ohlc=0) wait,
                        SUM(q_ohlc=1) bad, SUM(q_halt=1) halt
                      FROM bx_cand")->fetch(PDO::FETCH_ASSOC);
    bx_say('행 ' . number_format((int)$a['n']) . '건 / ' . (int)$a['nd'] . '거래일  ('
        . $a['a'] . ' ~ ' . $a['b'] . ')');
    bx_say('  출처 — 직접 고른 것 ' . (int)$a['pick'] . ' · 자동 ' . (int)$a['auto']);
    /* ★★등급은 «담는 기준»이 아니다 — 2026-08-09 에 적재 컷을 없앴다(BoxBrk::Z_Q75 주석).
     *   그래서 D 가 다수가 된다. 「컷 아래」라 적으면 거짓말이다(컷이 없다).
     * ★분모를 손으로 적지 않는다 — pick 은 지워질 수 있다(job=purgepick). 실제로 365 를
     *   박아 뒀다가 71건을 지운 뒤 「63/365」로 거짓말을 시작했다. */
    bx_say('  등급(표시·알림용 · 담는 기준 아님) — A ' . (int)$a['ga'] . ' · B ' . (int)$a['gb']
        . ' · C ' . (int)$a['gc']
        . ' · D ' . ((int)$a['n'] - (int)$a['ga'] - (int)$a['gb'] - (int)$a['gc'])
        . '  ※상위 25%(C↑)에 드는 pick 은 '
        . (int)$pdo->query("SELECT COUNT(*) FROM bx_cand WHERE src='pick' AND grade IN ('A','B','C')")
                   ->fetchColumn() . '/' . (int)$a['pick']);
    bx_say('  결과 — 익절 ' . (int)$a['tp'] . ' · 손절 ' . (int)$a['sl'] . ' · 미도달 ' . (int)$a['non']
        . ' · 모호 ' . (int)$a['amb'] . ' · 사후 미완 ' . (int)$a['wait'] . ' · 판정불가 ' . (int)$a['bad']);
    bx_say('  유형 — 불꽃형 ' . (int)$a['fl'] . ' · 거래정지 낀 것 ' . (int)$a['halt']
        . ' · 분봉 있는 것 ' . (int)$a['hm']);
    $ms = $pdo->query("SELECT min_stage, COUNT(*) n FROM bx_cand GROUP BY min_stage")->fetchAll(PDO::FETCH_KEY_PAIR);
    bx_say('  분봉 단계 — 없음 ' . (int)($ms[0] ?? 0) . ' · 1차 ' . (int)($ms[1] ?? 0)
        . ' · 완료 ' . (int)($ms[2] ?? 0));
    $fv = (int)$pdo->query("SELECT COUNT(*) FROM chart_fav_item WHERE src='boxbrk'")->fetchColumn();
    bx_say('  관심차트에 담긴 것 ' . $fv . '건');
    try {
        $al = $pdo->query("SELECT COUNT(*) n, MAX(sent_at) t FROM pf_alert_log WHERE kind='bx'")
                  ->fetch(PDO::FETCH_ASSOC);
        bx_say('  알림 보낸 신호 ' . (int)$al['n'] . '건 · 마지막 ' . ($al['t'] ?: '없음'));
    } catch (Throwable $e) { bx_say('  알림 장부 없음'); }
    break;
}

default:
    bx_say('알 수 없는 job — schema | migrate | daily | backfill | outcome | minfill | alert | status');
}
bx_say('끝 · ' . date('H:i:s'));
?>
