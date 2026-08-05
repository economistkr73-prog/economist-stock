<?php
/**
 * cron/dt_min.php — 단타 분봉 일일 수집 (하루 한 줄로 전부)
 *
 * ══ 왜 크론이 하나인가 ═════════════════════════════════════════════════
 * 요건 초안은 수집·치유·분할감지를 크론 <b>3개</b>로 나눴다. 그러나 이 셋은
 * 「마감 뒤 한 번」이라는 같은 시점에 매달려 있고 서로 순서가 있다
 * (수집 → 삭제 → 구멍 치유 → 분할 감지). 마감 묶음 `dart_eod` 와 같은 방식으로
 * <b>한 태스크 안의 단계</b>로 둔다 — 크론 사이트에 늘어나는 줄도 하나뿐이다.
 *
 * ══ ★삭제는 반드시 수집 «다음» ════════════════════════════════════════
 * 순서가 뒤집히면, 수집이 실패한 날에도 만료 삭제만 돌아 보관 일수가 조용히 줄어든다.
 *
 * ══ 30초 벽 ════════════════════════════════════════════════════════════
 * 20종목 × 1req/s = 40초라 응답 타임아웃(30초)을 넘는다. 이 서버는 SAPI 가
 * apache2handler 라 `fastcgi_finish_request` 가 없다 → env/cronbg.inc 의 <b>자기호출 bg</b> 필수.
 * (TASKS 에 'bg' => true 로 등록돼 있다)
 *
 * job
 *   daily   (기본) 당일분 수집 → 만료 삭제 → 구멍 치유 → 분할 감지
 *   heal    구멍만 치유 (손으로 돌릴 때)
 *   init    code=005930  한 종목 10거래일 초기 적재
 *   diag    code=005930  ★키움 시각 기준·거래량 기준을 네이버와 대조 [V-1][V-4]
 *   status  적재 현황
 *
 * SSH 실행 예
 *   php cron/dt_min.php job=diag code=005930
 *   php cron/dt_min.php job=daily
 */
require_once __DIR__ . '/_boot.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cronbg.inc';

$CLI = (PHP_SAPI === 'cli');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-dt-min';
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

define('DT_LOG', sys_get_temp_dir() . '/dt_min.log');
if (!empty($_GET['log'])) { cron_bg_show_log(DT_LOG, 80); exit; }

/* ?bg=1 이면 여기서 자기 자신에게 요청을 던지고 즉시 응답한다(30초 우회).
 * 시간 예산은 넉넉히 — 30종목 초기 적재가 겹쳐도 완주하게. */
cron_bg_begin(DT_LOG, max(0, (int)($_GET['sec'] ?? 900)));

function say(string $s): void { echo $s . "\n"; @flush(); }

$job  = (string)($_GET['job'] ?? 'daily');
$code = Dt::cleanCode((string)($_GET['code'] ?? ''));

$dt = new Dt($pdo);
$dt->ensureTables();
$kw = new Kiwoom($pdo);
$t0 = microtime(true);

/**
 * 한 종목·한 구간을 받아 저장한다.
 * 키움이 안 되면 네이버로 한 번 물러선다(당일·최근 7거래일까지만 가능).
 *
 * @return array ['bars'=>총 봉수, 'src'=>소스, 'err'=>?string]
 */
function dt_fetch_save(Dt $dt, Kiwoom $kw, string $code, string $from, bool $todayOnly = false): array
{
    $rows = []; $src = null; $err = null;

    if ($kw->hasKey()) {
        try {
            $r = $kw->minute($code, 1, $from, $todayOnly ? 2 : 8);
            $rows = $r['rows'];
            $src  = Dt::SRC_KIWOOM;
        } catch (Throwable $e) {
            $err = '키움: ' . $e->getMessage();
        }
    } else {
        $err = '키움 인증키 없음';
    }

    if (!$rows) {                        // ── 폴백: 네이버(당일 위주)
        try {
            $nv = (new NaverFinanceAPI())->getMinuteOhlc($code);
            $rows = Dt::normalize($nv['success'] ?? []);
            if ($rows) { $src = Dt::SRC_NAVER; $err = $err ? $err . ' → 네이버로 대체' : null; }
        } catch (Throwable $e) {
            $err = trim(($err ? $err . ' / ' : '') . '네이버: ' . $e->getMessage());
        }
    }
    if (!$rows) return ['bars' => 0, 'src' => null, 'err' => $err ?: '받아 온 봉이 없습니다'];

    $total = 0;
    foreach (Dt::byDay($rows) as $d => $day) {
        if ($d < $from) continue;
        $dt->barsUpsert($code, $day, $src);
        $n = count($day);
        $total += $n;
        $dt->logSave($code, $d, $n, Dt::statusOfBars($n), $src, $err,
                     substr($day[0]['t'], 11, 5), substr($day[$n - 1]['t'], 11, 5));
    }
    return ['bars' => $total, 'src' => $src, 'err' => $err];
}

switch ($job) {

    // ══ 현황 ════════════════════════════════════════════════════════════
    case 'status': {
        $days = $dt->tradingDays();
        say('보관 창: ' . ($days ? $days[0] . ' ~ ' . end($days) . ' (' . count($days) . '거래일)' : '(krx_amt 가 비었습니다)'));
        say('키움 인증키: ' . ($kw->hasKey() ? '있음' : '★없음 — env/kiwoom.inc'));
        $n = (int)$pdo->query("SELECT COUNT(*) FROM dt_min")->fetchColumn();
        say('dt_min: ' . number_format($n) . '행');
        foreach ($dt->poolList() as $p) {
            say(sprintf('  %-8s %-16s %2d일 %6s봉 %s', $p['code'], $p['name'],
                $p['days'], number_format($p['bars']),
                $p['holes'] ? '구멍 ' . $p['holes'] . '일' : ''));
        }
        break;
    }

    // ══ 진단 — ★S1 착수 전에 이걸 먼저 돌린다 [V-1][V-4] ═══════════════
    case 'diag': {
        if ($code === '') { say('code= 를 주세요. 예: job=diag code=005930'); break; }
        if (!$kw->hasKey()) { say('키움 인증키가 없어 대조할 수 없습니다 (env/kiwoom.inc).'); break; }
        /* 진단이 예외로 죽으면 「무엇을 물어보려 했는지」조차 안 보인다 —
         * 키·권한 문제일수록 여기서 잡아 «키움이 뭐라고 했는지»를 그대로 보여 준다. */
        try {
            $r = $kw->diag($code);
        } catch (Throwable $e) {
            say('진단 중단 — ' . $e->getMessage());
            break;
        }
        say('── 소스 대조 ' . $code);
        say('키움 봉 ' . (int)($r['kiwoom_bars'] ?? 0) . ' · 네이버 봉 ' . (int)($r['naver_bars'] ?? 0));
        if (!empty($r['raw_first'])) say('원본 첫 항목: ' . json_encode($r['raw_first'], JSON_UNESCAPED_UNICODE));
        if (empty($r['ok'])) { say($r['msg'] ?? '대조 실패'); break; }
        say(sprintf('대조 %d분 · 같은 분 일치 %d · 1분 당겨 일치 %d · 거래량 일치 %d',
            $r['compared'], $r['match_same_minute'], $r['match_shift_1min'], $r['volume_match']));
        say('★ ' . $r['verdict']);
        say('★ ' . $r['volume_note']);
        break;
    }

    // ══ 한 종목 초기 적재 ═══════════════════════════════════════════════
    case 'init': {
        if ($code === '') { say('code= 를 주세요.'); break; }
        $from = $dt->windowFrom();
        $r = dt_fetch_save($dt, $kw, $code, $from);
        $dt->poolInitStatus($code, $r['bars'] > 0 ? 2 : 9);
        say($code . ' — ' . number_format($r['bars']) . '봉 (' . $from . ' 이후)'
            . ($r['err'] ? ' · ' . $r['err'] : ''));
        break;
    }

    // ══ 구멍 치유 ═══════════════════════════════════════════════════════
    case 'heal': {
        $holes = $dt->holes(40);
        if (!$holes) { say('구멍 없음 — 보관 창이 꽉 찼습니다.'); break; }
        $from = $dt->windowFrom();
        $done = [];
        foreach ($holes as $h) {
            if (isset($done[$h['code']])) continue;      // 종목당 한 번이면 그 종목의 창이 다 채워진다
            $done[$h['code']] = 1;
            if (cron_bg_over()) { say('시간 예산 도달 — 나머지는 다음 실행에서'); break; }
            $r = dt_fetch_save($dt, $kw, $h['code'], $from);
            say('  치유 ' . $h['code'] . ' — ' . number_format($r['bars']) . '봉'
                . ($r['err'] ? ' · ' . $r['err'] : ''));
            if ($r['bars'] === 0) {
                $dt->logSave($h['code'], $h['d'], 0, 'fail', null, $r['err'], null, null, true);
            }
            usleep(Kiwoom::GAP_USEC);
        }
        break;
    }

    // ══ 매일 (크론) ═════════════════════════════════════════════════════
    case 'daily':
    default: {
        $today = date('Y-m-d');
        if (!$dt->isTradingDay($today)) {
            say($today . ' — 거래일이 아닙니다(또는 마감 묶음이 아직 안 돌았습니다). 종료.');
            break;
        }
        /* ★수집 대상 = 단타 풀 ∪ 살아있는 포지션 (2026-08-05 · 단일본 Dt::targetCodes).
         * 보유 종목을 dt_pool 에 «담지» 않는 이유는 그 함수 주석에 있다(FIFO 가 봉을 지운다).
         * 아래 ③④⑤ 도 같은 단일본을 보므로 여기만 넓히면 어긋날 자리가 없다. */
        $codes = $dt->targetCodes();
        if (!$codes) { say('수집할 종목이 없습니다 (단타 풀·보유 포지션 모두 비었습니다).'); break; }
        $nPool = count($dt->activeCodes());
        $nHeld = count($dt->heldCodes());
        $from  = $dt->windowFrom();
        say('── ① 당일 수집 (' . count($codes) . '종목 = 단타 ' . $nPool . ' + 보유 ' . $nHeld
            . ' − 겹침 ' . max(0, $nPool + $nHeld - count($codes)) . ' · 창 ' . $from . ' ~)');

        $ok = 0; $fail = 0; $errs = [];
        foreach ($codes as $c) {
            if (cron_bg_over()) { say('시간 예산 도달 — 나머지는 치유 단계가 이어받습니다'); break; }
            $r = dt_fetch_save($dt, $kw, $c, $today, true);
            if ($r['bars'] > 0) { $ok++; } else { $fail++; $errs[] = (string)$r['err']; $dt->logSave($c, $today, 0, 'fail', null, $r['err'], null, null, true); }
            say(sprintf('  %s %6s봉 %s', $c, number_format($r['bars']), $r['err'] ?: ''));
            usleep(Kiwoom::GAP_USEC);
        }
        say("수집 완료 — 성공 {$ok} · 실패 {$fail}");

        // ── ② 만료 삭제 (★반드시 수집 다음) ─────────────────────────
        $p = $dt->prune();
        say('── ② 만료 삭제 — 봉 ' . number_format($p['bars']) . '행 · 로그 ' . $p['logs']
            . '행 · 해지종목 잔여 ' . $p['dead'] . '행 (창 시작 ' . $p['from'] . ')');

        // ── ③ 구멍 치유 ─────────────────────────────────────────────
        $holes = $dt->holes(40);
        say('── ③ 구멍 치유 — 대상 ' . count($holes) . '건');
        $done = [];
        foreach ($holes as $h) {
            if (isset($done[$h['code']])) continue;
            $done[$h['code']] = 1;
            if (cron_bg_over()) { say('  시간 예산 도달 — 나머지는 내일'); break; }
            $r = dt_fetch_save($dt, $kw, $h['code'], $from);
            say('  ' . $h['code'] . ' — ' . number_format($r['bars']) . '봉' . ($r['err'] ? ' · ' . $r['err'] : ''));
            if ($r['bars'] === 0) $dt->logSave($h['code'], $h['d'], 0, 'fail', null, $r['err'], null, null, true);
            usleep(Kiwoom::GAP_USEC);
        }

        /* ── ④ 액면분할·병합 감지 ──
         * 수정계수를 곱하지 않고 <b>지우고 다시 받는다</b> — 10거래일이면 5콜이라
         * 계수 곱셈보다 싸고 확실하다. */
        $sus = $dt->splitSuspects();
        say('── ④ 상장주식수 급변 — ' . count($sus) . '종목');
        foreach ($sus as $s) {
            say(sprintf('  %s 주식수 %s → %s · 전량 재수집', $s['code'],
                number_format((int)$s['prev_shrs']), number_format((int)$s['cur_shrs'])));
            $dt->wipe($s['code']);
            $r = dt_fetch_save($dt, $kw, $s['code'], $from);
            say('    재수집 ' . number_format($r['bars']) . '봉' . ($r['err'] ? ' · ' . $r['err'] : ''));
            usleep(Kiwoom::GAP_USEC);
        }

        /* ── ⑤ 데이터 레벨 감시 (CRON.md §2.5 ② 와 같은 자리) ──
         * 이 파일은 수집 실패를 <b>전부 자체 catch 로 삼킨다</b> — 키움이 죽어도 네이버로 물러서야 하기 때문이다.
         * 그래서 예외가 안 올라가 «중앙 실패 알림»(cron_job.php)이 못 보고, bg 라 cron-job.org 눈에도
         * 늘 성공이다. 20종목이 전멸해도 로그를 사람이 열어야만 알게 된다 — 실제로 키움 IP 가 막혀 있던
         * 동안이 그 상태였다(네이버 폴백이 받쳐 줘 티가 안 났을 뿐이다).
         * → §2.5 규칙 3 대로 «삼키는 크론은 스스로 쏜다». 스로틀은 cron_fail_notify 가 갖고 있다(하루 1회).
         * 판정은 시도 횟수가 아니라 «치유까지 끝난 뒤 실제로 남은 것»으로 한다. */
        $got  = $dt->collectedCount($today);
        $want = count($codes);
        say('── ⑤ 감시 — ' . $got . '/' . $want . ' 종목 적재');
        if ($want > 0 && $got * 2 < $want) {                 // 절반도 못 받았다
            $why = $errs ? ' · 대표 사유: ' . $errs[0] : '';
            say('  ★경고 — 실패율 과반');
            if (function_exists('cron_fail_notify')) {       // 디스패처를 거쳐 돌 때만 존재
                cron_fail_notify('dt_min', "분봉 적재 {$got}/{$want} 종목{$why}");
            }
        }
        break;
    }
}

say(sprintf('총 %.1f초', microtime(true) - $t0));
cron_bg_finish();
?>
