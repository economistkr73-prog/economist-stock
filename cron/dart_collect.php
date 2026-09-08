<?php
/**
 * cron_dart_collect.php — DART 재무 수집 배치 (분기 재무제표 · 연도별 주식수)
 *
 * 화면(stock/api.php)에서 돌리기엔 너무 긴 수집을 여기서 맡는다.
 *   - 분기 재무제표 : 100종목씩 묶어 부르므로 (연도 × 보고서) 당 40회. 11년 3분기면 1,320회 ≈ 8분
 *   - 연도별 주식수 : 다중회사 API 가 없어 종목마다 1회. 2015~2022 면 15,000회 ≈ 54분
 * 둘 다 <b>이미 채운 것은 건너뛴다</b> — 중간에 끊겨도 다시 부르면 이어받는다.
 *
 * DART 는 하루 20,000회다. budget 으로 이번 실행의 상한을 걸어 두면 한도를 넘기지 않는다.
 *
 * ★★★ 크론 타임아웃은 <b>30초</b>다 (cron-job.org 실측 2026-07-30 — job=fresh 78초가
 *   "Failed (timeout)" 으로 매일 실패하고 있었다). 30초를 넘는 잡은 <b>bg=1</b> 을 붙인다.
 *   bg=1 은 "OK" 만 먼저 내보내고 연결을 닫아 크론에게 성공을 알린 뒤 본작업을 계속 돈다.
 *   진행 내용은 화면이 아니라 로그로 가므로 <b>?job=log</b> 로 읽는다.
 *
 * ══ 걸어 둔 크론 — <b>세 줄이면 끝난다</b> (실제 등록값 · 2026-07-30 확인) ═══
 *   5 8 * * 1-5    →  ?key=econ-dart-collect&job=fresh&bg=1  (DART 재무 · 78초 · bg)
 *   5 13 * * *     →  ?key=econ-dart-collect&job=krx         (KRX 상장주식수 · 9~20초)
 *   50 15 * * *    →  ?key=econ-dart-collect&job=eod         (시세 메우기 + 오늘 고·저 + 일봉 · 10초)
 *
 *   fresh 만 평일(1-5)이다 — DART 접수가 영업일 기준이라 무해하다.
 *   krx 는 <b>오후 1회로 확정</b>했다. T+1 자료가 11:58 에는 올라와 있으므로 13:05 단발로 충분하다
 *   (오전·오후 2회는 올라오는 시각이 불확실할 때의 안전장치였다). 새벽으로 당기면 하루 묵은 값을 받는다.
 *
 *   ★ job=eod 가 quotes + range 를 <b>한 줄로 묶은 것</b>이다. 둘 다 마감 직후에 돌아야 하고
 *     합쳐도 3초라 나눌 이유가 없다 — 크론 항목을 늘리지 않으려고 묶었다.
 *     따로 돌려 보고 싶으면 job=quotes · job=range 가 그대로 남아 있다.
 *
 *   확인:  ?key=econ-dart-collect&job=log      (지난 bg 실행이 무엇을 했는지)
 *          ?key=econ-dart-collect&job=status   (수집 현황)
 *          ?key=econ-dart-collect&job=move     (마감 변동 요약 미리보기 — 보내지 않음 · 실제 발송은 eod 끝)
 *
 * ══ 크론에 걸지 않는 것 (SSH 전용 — 한 번 돌리면 끝나는 것들) ═══════════
 *   job=range&full=1   일별 고·저 씨 뿌리기 (6분) — 최초 1회면 끝이다
 *   job=quarter        분기 재무제표 최초 적재
 *   job=shares         연도별 주식수
 *   job=alot           연도별 배당(DPS·수익률) 백필 — 매일 몫은 fresh 안에 있다
 *
 * 시각이 다른 이유
 *   DART  아무 때나 받아도 된다.
 *   KRX   T+1 인데다 <b>다음 날 오전에야</b> 올라온다(실측 2026-07-29 — 01:19 엔 7/28 이 없고 11:58 엔 있었다).
 *         새벽에 같이 돌리면 하루 더 묵은 값을 받으므로 오전·오후 두 번 돌려 그날 안에 잡는다.
 *         이미 최신이면 같은 값 덮어쓰기라 무해하다.
 *   시세  정규장 마감(15:30) 직후여야 그날 종가가 잡힌다.
 *   고저  시세를 메운 뒤에 돌린다. 마감 전에 돌리면 그날 고가·저가가 빠진다.
 *
 *         ★★ 이 잡의 핵심은 <b>일봉을 다시 받지 않는다</b>는 것이다.
 *
 *           일봉 API 는 묶음 호출이 없어 종목마다 1회다 — 전종목이 2,766회 · 6분.
 *           크론 타임아웃 30초에는 어떻게 쪼개도 들어가지 않는다.
 *           (간격을 20ms 로 줄이면 111초에 되지만 그건 초당 25건이라 한 IP 가 낼 속도가 아니다.
 *            차단당하면 이 열 두 개가 아니라 <b>사이트 전체의 주가가 멈춘다</b> —
 *            all_stock_info 장중 크론 · 포트폴리오 시세 · 시뮬레이터 일봉이 전부 네이버다.)
 *
 *           그래서 <b>일별 고·저를 우리 DB(stock_daily_range)에 쌓는다</b>.
 *           매일 필요한 것은 "오늘 한 줄"이고, 그건 실시간 폴링 API 가 <b>100종목씩</b> 준다
 *           (실측 2026-07-29 — 같은 날 일봉과 값이 완전히 일치). 전종목 <b>28회 · 1초</b>다.
 *           6개월 집계는 그 원본에서 SQL 한 문장으로 접는다 —
 *           <b>창이 스스로 흐르므로 주기적 재수집이 아예 없다</b>.
 *
 *         씨 뿌리기(job=range&full=1)만 종목마다 1회다. 최초 1회면 끝이라 SSH 로 돌린다.
 *
 * 왜 "마감일 지나고 한 번"이 아니라 매일인가 (2026-07-29 실측):
 *   공시는 마감일 하루에 72~80% 가 몰려 들어오지만, <b>거기서 끝나지 않는다</b>.
 *     1분기 5/15 마감 → 그날까지 98.0% · 나머지는 5/29 까지
 *     반기  8/14 마감 → 그날까지 96.8% · 나머지는 8/29 까지
 *     3분기 11/14 마감 → 그날까지 97.5% · 나머지는 11/28 까지
 *     사업보고서는 성격이 달라 3/16~3/23 에 넓게 퍼지고 꼬리가 4월 말까지 간다(주총 일정)
 *   마감 다음날 한 번만 받으면 <b>2~3%(60~90종목)를 놓친다</b>. 늦게 내는 회사는 대개
 *   감사·재무 이슈가 있는 쪽이라 스크리너에서는 오히려 봐야 할 대상이다.
 *   게다가 결산월이 12월이 아닌 회사(방림 9월·만호제강 6월·현대약품 11월…)는 <b>연중 아무 때나</b> 낸다.
 *
 * 비용이 싸서 창(window)을 계산해 아낄 이유가 없다 — 5슬롯 × 40회 = 200회로 하루 한도의 1% 다.
 * 그래서 마감일 표도, 상태 저장도 두지 않는다. "오늘에서 최신 5슬롯"이 로직의 전부고,
 * 달력이 흐르면 슬롯이 저절로 앞으로 밀린다.
 *
 * 사용 예 (cafe24 cron 은 URL 호출)
 *   /cron_dart_collect.php?key=econ-dart-collect&job=fresh          ← 매일 (DART)
 *   /cron_dart_collect.php?key=econ-dart-collect&job=krx            ← 매일 (KRX)
 *   /cron_dart_collect.php?key=econ-dart-collect&job=quotes         ← 매일 (시세 메우기)
 *   /cron_dart_collect.php?key=econ-dart-collect&job=status
 *   php cron_dart_collect.php job=quarter from=2016 to=2026         ← 최초 적재
 *   php cron_dart_collect.php job=shares  from=2015 to=2022 budget=1200
 *
 * 파라미터
 *   job     fresh | krx | eod | quotes | range | quarter | shares | alot | slots | log | status
 *           (기본 status — 아무것도 건드리지 않고 현황만 본다)
 *           eod = quotes + range. 마감 뒤 한 줄로 묶은 것 (크론용)
 *   bg      1 이면 즉시 200 OK 로 연결을 닫고 뒤에서 계속 돈다 (크론 30초 우회).
 *           진행은 화면이 아니라 로그로 간다 → ?job=log 로 읽는다
 *   n       job=log 가 보여 줄 마지막 줄 수 (기본 60)
 *   from,to 사업연도 범위 (quarter · shares)
 *   slots   fresh 가 받을 최신 슬롯 수 (기본 5)
 *   keep    krx 가 남길 기준일 수 (기본 10)
 *   months  range 가 볼 구간 길이 (기본 6개월)
 *   full    range 가 일별 원본에 씨를 뿌린다(종목마다 일봉 1회 · 6분 · SSH 전용).
 *           없으면 오늘 한 줄만 추가하고 집계를 다시 접는다 (28회 · 몇 초 · 크론용)
 *   gap     range&full=1 의 호출 간격(ms). 기본 100 = 초당 8건. 줄이지 말 것 — 위 주석 참조
 *   force   quotes·range 가 신선도를 따지지 않고 전부 다시 받는다
 *   budget  이번 실행에서 쓸 DART 호출 수 상한 (0 이면 제한 없음)
 *   sec     시간 예산(초). 넘으면 그 자리에서 멈춘다 (기본 0 = 무제한, bg=1 이면 600)
 *
 * ★ 서버(PHP·게이트웨이) 쪽 한도는 넉넉하다 — 실측 106초까지 통과했고 340초짜리도 돌았다.
 *   막는 것은 <b>크론이 기다려 주는 30초</b>다. 그래서 해법이 "쪼개기"가 아니라 bg=1 이다.
 */

// CLI 로 돌릴 때 DOCUMENT_ROOT 를 웹 루트로 세팅한다 (cnt.inc 오토로더가 그 값을 쓴다)
require_once __DIR__ . '/_boot.php';

require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cnt.inc";
require_once $_SERVER['DOCUMENT_ROOT'] . "/env/dart.inc";

$CLI = (PHP_SAPI === 'cli');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

// 웹으로 열 때만 토큰을 본다. CLI 는 서버에 들어와야 돌릴 수 있으니 그 자체가 자격이다.
$TOKEN = 'econ-dart-collect';
if (!$CLI && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

// CLI 는 `job=quarter` 꼴 인자를 $_GET 처럼 받는다
if ($CLI) {
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $_GET[$k] = $v; }
    }
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

/* ── bg=1 : 크론 30초 타임아웃 우회 ─────────────────────────────────────
 *
 * 외부 HTTP 크론(cron-job.org)은 응답을 <b>30초</b>까지만 기다린다. 그보다 긴 잡은
 * "Failed (timeout)" 으로 매일 실패하고, 더 나쁘게는 <b>출력을 flush 하는 순간
 * PHP 가 끊긴 연결을 알아채고 스크립트를 죽인다</b> — 뒷부분이 영원히 안 돈다.
 * job=fresh(78초)가 정확히 그 상태였다.
 *
 * ★★ 이 레포의 다른 크론(cron_naver_collect · cron_keyword_collector)이 쓰는
 *   「Content-Length + Connection: close + fastcgi_finish_request」 패턴은
 *   <b>이 서버에서 듣지 않는다</b>. 실측(2026-07-30)으로 확인했다:
 *     · SAPI 가 apache2handler 라 fastcgi_finish_request 가 <b>아예 없다</b>
 *     · Content-Type 을 html·plain·미지정으로 바꿔 봐도 응답은 12초 전부 기다렸다
 *   (naver 쪽이 0.2초에 돌아온 것은 그 회차가 이미 done 이어서 no-op 였던 것뿐이다.)
 *
 * 그래서 <b>자기 자신에게 비동기 요청을 던지고 즉시 끝낸다</b>.
 * 던지는 쪽은 응답을 읽지 않고 소켓을 닫으므로 0.1초면 끝나고(크론은 성공을 받는다),
 * 받은 쪽(run=1)은 ignore_user_abort(true) 로 호출자가 끊어도 계속 돈다.
 * 실측 — 던지기 0.07초 · 뒤에서 12초 완주.
 *
 * ★ 뒤에서 도는 쪽은 화면이 없다. 그래서 say() 는 <b>로그 파일</b>에 쓰고,
 *   ?job=log 로 읽는다. 로그는 웹 루트 밖(임시 디렉터리)이라 URL 로 열리지 않는다.
 *
 * ★ 좀비 방지: 뒤에서 도는 쪽은 시간 예산을 반드시 갖는다(기본 600초).
 *   수동 호출(bg·run 없음)은 연결이 끊기면 그대로 죽는다.
 */
define('DART_LOG_PATH', sys_get_temp_dir() . '/dart_collect.log');
$RUN = !$CLI && !empty($_GET['run']);          // 뒤에서 도는 쪽
$BG  = !$CLI && !empty($_GET['bg']) && !$RUN;  // 던지는 쪽
define('DART_TO_LOG', $RUN);

if ($BG) {
    // 같은 인자를 그대로 넘기되 bg 를 run 으로 바꿔 던진다 (받은 쪽이 다시 던지면 안 된다)
    $q = $_GET; unset($q['bg']); $q['run'] = '1';
    $host = preg_replace('/[^0-9A-Za-z.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'economist.kr'));
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/cron_dart_collect.php') . '?' . http_build_query($q);

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp  = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);

    if ($fp) {
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\n"
                  . "User-Agent: dart-cron-dispatch\r\nConnection: Close\r\n\r\n");
        stream_set_blocking($fp, false);   // 응답을 기다리지 않는 것이 이 기법의 요점이다
        fclose($fp);
        exit("queued: job=" . (string)($_GET['job'] ?? '') . "\n진행은 ?key=…&job=log 로 봅니다.\n");
    }
    /* 던지지 못했으면 <b>그냥 여기서 돈다</b>. 크론은 타임아웃으로 실패를 기록하겠지만
     * ignore_user_abort 덕에 작업은 끝난다 — 아무것도 안 하는 것보다 낫다. */
    $RUN = true;
    say('★ 자기호출 실패(' . $errno . ' ' . $errstr . ') — 이 요청에서 그대로 처리합니다.');
}

if ($RUN) {
    ignore_user_abort(true);
    @file_put_contents(DART_LOG_PATH,
        str_repeat('=', 60) . "\n" . date('Y-m-d H:i:s') . ' job=' . (string)($_GET['job'] ?? '') . "\n");
} elseif (!$CLI) {
    ignore_user_abort(false);      // 수동 호출: 끊기면 종료 (좀비 방지)
}

$job    = (string)($_GET['job']    ?? 'status');
$budget = (int)   ($_GET['budget'] ?? 0);

/* 지난 bg 실행이 무슨 일을 했는지 본다. 본작업은 아무것도 건드리지 않는다. */
if ($job === 'log') {
    $n = max(1, min(500, (int)($_GET['n'] ?? 60)));
    if (!is_file(DART_LOG_PATH)) exit("아직 bg 실행 기록이 없습니다 (" . DART_LOG_PATH . ")\n");
    $lines = @file(DART_LOG_PATH) ?: [];
    echo DART_LOG_PATH . ' — ' . count($lines) . "줄 중 마지막 {$n}줄\n";
    echo str_repeat('-', 60) . "\n";
    echo implode('', array_slice($lines, -$n));
    exit;
}
// 시간 예산은 기본으로 걸지 않는다 — 웹 요청도 실측 106초까지 통과했고(2026-07-29),
// 잘려도 다음 실행이 통째로 다시 받으므로 잃는 게 없다. 긴 작업은 budget= 로 조각낸다.
$secs   = (int)   ($_GET['sec']    ?? 0);
// 뒤에서 도는 쪽은 연결이 끊긴 뒤에도 계속 도므로 반드시 끝나는 지점이 있어야 한다 (좀비 방지)
if ($RUN && $secs <= 0) $secs = 600;
$start  = microtime(true);

function say(string $m): void
{
    $line = '[' . date('H:i:s') . '] ' . $m . "\n";
    // bg 는 연결이 이미 닫혀 있다 — echo 는 사라지므로 파일에 남긴다 (?job=log 로 읽는다)
    if (DART_TO_LOG) { @file_put_contents(DART_LOG_PATH, $line, FILE_APPEND); return; }
    echo $line;
    if (function_exists('ob_flush')) { @ob_flush(); } flush();
}
/** 시간 예산을 넘겼는가 */
function over(float $start, int $secs): bool
{
    return $secs > 0 && (microtime(true) - $start) >= $secs;
}

$dart = new Dart($pdo);
if (!$dart->hasKey()) exit("DART 인증키가 없습니다 (env/dart.inc).\n");
$dart->ensureTables();

// ── 현황 ───────────────────────────────────────────────────────────────
function show_status(PDO $pdo, Dart $dart): void
{
    say('── 보고서별 적재 현황');
    $rows = $pdo->query("
        SELECT bsns_year, reprt_code, COUNT(*) n
          FROM stock_financial GROUP BY bsns_year, reprt_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grid = [];
    foreach ($rows as $r) $grid[(int)$r['bsns_year']][$r['reprt_code']] = (int)$r['n'];
    krsort($grid);

    say(sprintf('%-6s %8s %8s %8s %8s', '연도', '1분기', '반기', '3분기', '연간'));
    foreach ($grid as $y => $c) {
        say(sprintf('%-6s %8s %8s %8s %8s', $y,
            number_format($c['11013'] ?? 0), number_format($c['11012'] ?? 0),
            number_format($c['11014'] ?? 0), number_format($c['11011'] ?? 0)));
    }

    say('');
    say('── 연도별 주식수 (사업보고서 기준)');
    foreach ($dart->sharesStatus() as $s) {
        say(sprintf('%-6s 전체 %5s · 조회끝 %5s · 값있음 %5s',
            $s['bsns_year'], number_format((int)$s['total']),
            number_format((int)$s['done']), number_format((int)$s['ok'])));
    }

    say('');
    say('── KRX 시세 (기준일별)');
    $krx = $pdo->query("
        SELECT bas_dd, COUNT(*) n, MAX(updated_at) up
          FROM krx_daily GROUP BY bas_dd ORDER BY bas_dd DESC LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!$krx) {
        say('  아직 없습니다 — job=krx 로 받으세요.');
    } else {
        foreach ($krx as $k) {
            say(sprintf('  %s  %5s종목  받은시각 %s', $k['bas_dd'], number_format((int)$k['n']), $k['up']));
        }
    }
}

// ── 매일 갱신 (job=fresh) ──────────────────────────────────────────────
/**
 * 오늘 기준 "지금 받을 값어치가 있는" 보고서 슬롯 N개 — 최신 것 먼저.
 *
 * 시간축은 (사업연도 × 보고서)를 <b>대상 기간이 끝나는 날</b>로 줄 세운 것이다:
 *   1분기 3/31 · 반기 6/30 · 3분기 9/30 · 사업보고서 12/31
 *
 * 기간이 아직 안 끝난 슬롯도 <b>한 칸까지는</b> 넣는다(+92일).
 * 결산월이 12월이 아닌 회사는 우리 달력보다 먼저 그 슬롯을 내기 때문이다 —
 * 실제로 2026-07-29 에 이미 2026 3분기가 16종목 들어와 있었다(만호제강·신성통상 등 6월 결산).
 *
 * 마감일 표를 두지 않는 이유: 마감일은 결산월마다 다르고 공휴일로 밀린다.
 * "기간이 끝났는가"만 보면 어느 회사에도 맞고, 늦게 내는 회사는 다음 날 받으면 그만이다.
 *
 * @return array 각 원소 ['year'=>int, 'reprt'=>string, 'end'=>DateTimeImmutable]
 */
function fresh_slots(int $n = 5, ?string $today = null): array
{
    $t   = new DateTimeImmutable($today ?? 'today');
    $cut = $t->modify('+92 days');
    $y0  = (int)$t->format('Y');

    $ends = [
        Dart::REPRT_Q1     => '-03-31',
        Dart::REPRT_H1     => '-06-30',
        Dart::REPRT_Q3     => '-09-30',
        Dart::REPRT_ANNUAL => '-12-31',
    ];

    $slots = [];
    for ($y = $y0 - 2; $y <= $y0 + 1; $y++) {
        foreach ($ends as $rc => $md) {
            $end = new DateTimeImmutable($y . $md);
            if ($end > $cut) continue;
            $slots[] = ['year' => $y, 'reprt' => $rc, 'end' => $end];
        }
    }

    usort($slots, fn($a, $b) => $b['end'] <=> $a['end']);
    return array_slice($slots, 0, max(1, $n));
}

/**
 * 매일 돌리는 갱신. 최신 슬롯들을 통째로 다시 받는다.
 *
 * "무엇이 새로 들어왔는지" 를 따지지 않고 그냥 다시 받는 이유:
 * 어차피 한 슬롯이 40회·20초다. 새 것만 골라내는 로직을 두면 그 로직이 틀렸을 때
 * <b>조용히 빠지는</b> 종목이 생기는데, 통째로 받으면 그런 구멍이 아예 없다.
 * 같은 값을 덮어쓰는 것은 해가 없고, 정정공시도 이 방식이라야 따라온다.
 */
/** fresh 한 번에 메울 순이익 결측 행수 — 슬롯당. 5슬롯이면 최대 75콜(한도의 0.4%) */
const FRESH_NIFIX_CAP = 15;

/** fresh 한 번에 받을 배당 결측 종목 수 — 시즌(3~5월)에 하루 수십~수백 건, 평시 0건.
 *  콜당 ~0.4초라 상한 300 이면 최악 2분 — bg 예산(600초) 안이다. */
const FRESH_ALOT_CAP = 300;

function job_fresh(Dart $dart, int $n, int $budget, float $start, int $secs): void
{
    $slots = fresh_slots($n);
    $per   = (int)ceil($dart->corpCodeCount() / Dart::MULTI_MAX);
    say('최신 ' . count($slots) . '개 슬롯 갱신 — 슬롯당 약 ' . $per . '회 호출');

    $used = 0;
    foreach ($slots as $s) {
        if (over($start, $secs))             { say('시간 예산 초과 — 멈춥니다. 내일 다시 받습니다.'); return; }
        if ($budget > 0 && $used >= $budget) { say('호출 예산 소진 — 멈춥니다.'); return; }

        $lab = $s['year'] . ' ' . Dart::reprtName($s['reprt']);
        $t0  = microtime(true);
        try {
            $r = $dart->collectFinancials($s['year'], $s['reprt']);
        } catch (Throwable $e) {
            say("{$lab} — 실패: " . $e->getMessage());
            if (strpos($e->getMessage(), '020') !== false) return;   // 일일 한도 초과
            continue;
        }
        $used += $per;
        say(sprintf('%-12s 종목 %5s · 저장 %5s (%.1f초)  [기간종료 %s]',
            $lab, number_format($r['companies']), number_format($r['saved']),
            microtime(true) - $t0, $s['end']->format('Y-m-d')));
    }

    /* ★ 순이익 결측 보수 (2026-08-04) — 방금 받은 슬롯만.
     *
     * 주요계정 API 는 「연결당기순이익」처럼 <b>표준계정을 안 쓴 회사</b>의 순이익 행을 아예 안 준다
     * (현대차·호텔신라·SK이노 …). 그래서 매출은 있는데 순이익만 NULL 인 행이 매 슬롯 20~30 개씩 생긴다.
     * 여기서 지금 슬롯만 메우고, 옛 연도는 `job=nifix` 로 따로 돌린다 — 호출이 한 번에 몰리지 않게.
     * 자세한 원리는 Dart::repairNetIncome() 주석. */
    $fx = ['done' => 0, 'filled' => 0, 'remain' => 0];
    foreach ($slots as $s) {
        if (over($start, $secs)) break;
        $r = $dart->repairNetIncome(FRESH_NIFIX_CAP, $s['year'], $s['reprt'], true, null,
                                    fn() => over($start, $secs));
        $fx['done']   += $r['done'];
        $fx['filled'] += $r['filled'];
        $fx['remain'] += $r['remain'];
    }
    if ($fx['done']) say(sprintf('순이익 보수 — 시도 %d · 채움 %d · 이 슬롯들에 남음 %d',
                                 $fx['done'], $fx['filled'], $fx['remain']));

    /* ★ 배당 자동 갱신 (2026-09-02) — 새 사업보고서가 들어와 연간 재무 행이 생겼는데
     * 배당(stock_fundamental.dps)이 아직 없는 종목만 그 자리에서 받는다. 시즌(3~5월)에
     * 하루 수십~수백 콜, 평시 0콜 — 이 단계 덕에 배당은 앞으로 손댈 일이 없다.
     * 최신 두 연도만 본다(사업보고서는 이듬해 3~4월에 나온다 · 비12월 결산이 당해분을 조기 제출). */
    $ab = 0;
    foreach ([(int)date('Y') - 1, (int)date('Y')] as $ay) {
        if (over($start, $secs)) break;
        $left = FRESH_ALOT_CAP - $ab;
        if ($left <= 0) break;
        try {
            $r = $dart->collectAlot($ay, $left, true);
        } catch (Throwable $e) {
            say('배당 갱신 실패(무시하고 계속): ' . $e->getMessage());
            break;
        }
        $ab += $r['done'];
        if ($r['done']) say(sprintf('배당 갱신 %d — 처리 %d · 배당있음 %d · 남음 %d',
                                    $ay, $r['done'], $r['filled'], $r['remain']));
    }

    say('갱신 완료.');
}

// ── 순이익 결측 보수 (job=nifix) ───────────────────────────────────────
/**
 * 매출은 있는데 순이익만 빈 행을 전체 재무제표 API 로 메운다.
 *
 * 옛 연도까지 한 번에 채우는 <b>일회성 배치</b>다. 매일 도는 몫은 job=fresh 안에 들어 있다.
 * 실측(2026-08-04) 대상 897행 · 233종목 — 1행 = 1콜이라 하루 한도(20,000)의 4.5% 다.
 *
 * ★ 이어받기 설계라 budget= 로 조각내 반복 실행해도 잃는 게 없다
 *   (남은 것만 고르므로 두 번 부르지 않는다).
 */
function job_nifix(Dart $dart, int $limit, int $year, string $reprt, float $start, int $secs): void
{
    $t0 = microtime(true);
    $r  = $dart->repairNetIncome(
        $limit, $year ?: null, $reprt !== '' ? $reprt : null, true,
        function ($done, $tot, $filled) { say(sprintf('  … %d/%d · 채움 %d', $done, $tot, $filled)); },
        fn() => over($start, $secs)
    );
    say(sprintf('순이익 보수 — 시도 %s · 채움 %s · 못 찾음 %s · 남음 %s (%.1f초)',
        number_format($r['done']), number_format($r['filled']),
        number_format($r['empty']), number_format($r['remain']), microtime(true) - $t0));

    $g = $dart->netIncomeGap();
    say(sprintf('전체 결측 현황 — %s행 · %s종목',
        number_format((int)$g['rows_missing']), number_format((int)$g['codes_missing'])));
}

// ── KRX 시세·상장주식수 (job=krx) ──────────────────────────────────────
/**
 * 가장 최근 거래일의 전종목 시세·상장주식수를 받고, 오래된 기준일은 정리한다.
 *
 * DART 와 성격이 달라 <b>크론 시각이 중요하다</b>. KRX 는 T+1 인데다 다음 날 오전에야 올라온다 —
 * 실측(2026-07-29): 01:19 에는 7/28 이 없어 7/27 이 최신, 11:58 에는 7/28 이 있었다.
 * 그래서 DART 크론(새벽)에 얹으면 하루 더 묵은 값을 받는다. 오전 이후로 따로 건다.
 *
 * collectLatest() 가 "그 시점의 최신 거래일" 을 찾아 오므로 하루 여러 번 돌려도 안전하다
 * (같은 값 덮어쓰기). 호출도 시장 2개뿐이라 사실상 공짜다.
 */
function job_krx(PDO $pdo, int $keep): void
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/krx.inc';
    $krx = new Krx($pdo);
    if (!$krx->hasKey()) { say('KRX 인증키가 없습니다 (env/krx.inc).'); return; }
    $krx->ensureTables();

    $before = $krx->lastDate();
    $t0 = microtime(true);
    $r  = $krx->collectLatest();

    if (!$r['rows']) {
        say('받을 거래일이 없습니다 — 연휴이거나 아직 안 올라왔습니다. 저장된 최신은 '
            . ($before !== '' ? $before : '없음'));
        return;
    }

    $dd  = substr($r['date'], 0, 4) . '-' . substr($r['date'], 4, 2) . '-' . substr($r['date'], 6, 2);
    /* 며칠 묵은 자료를 받았는지 남긴다.
     * KRX 는 T+1 인데 <b>다음 날 몇 시에 올라오는지가 공개돼 있지 않다</b>(공식 안내에 없음).
     * 실측으로 아는 것은 01:19 엔 없고 11:58 엔 있다는 것뿐이라, 이른 시각 크론이 전일치를 잡는지는
     * 이 로그가 며칠 쌓여야 판명된다. 1 이면 전일치(정상), 2 이상이면 그 시각엔 아직 안 올라온 것이다. */
    $lag = (int)floor((strtotime(date('Y-m-d')) - strtotime($dd)) / 86400);
    say(sprintf('%s 기준 %s종목 · 오늘로부터 %d일 전 (%.1f초)%s', $dd, number_format($r['rows']),
        $lag, microtime(true) - $t0, $dd === $before ? ' — 이미 있던 날짜를 새로 덮었습니다' : ''));

    /* ★종목 마스터 동기화 (2026-09-04) — krx_daily → stock_master (API 0회 · classes/StockName.class).
     *   종목명·시장구분의 주인은 이 표다. 시세 스냅샷(all_stock_info)을 마스터로 쓰다가 네이버 목록이
     *   빠뜨린 종목(유니트론텍 142210)이 화면에 코드로 뜬 사고의 재발 방지. prune 보다 먼저 한다. */
    try {
        $m = StockName::sync($pdo);
        say(sprintf('종목 마스터 동기화 — %s 기준 %s종목 (신규 %d · 마스터 총 %s)', $m['date'],
            number_format($m['rows']), $m['inserted'], number_format($m['total'])));
    } catch (Throwable $e) {
        say('⚠ 종목 마스터 동기화 실패: ' . $e->getMessage());
    }

    /* ★ 지우기 전에 시세·거래대금을 장기 원장으로 옮긴다.
     *
     * krx_daily 는 최근 며칠만 두고 지운다(prune). 그런데 krx_amt 는 <b>전종목 일별 원장</b>이라
     * (2026-07-31 확장 — 거래대금 60일 신고가 스크리너의 데이터) 몇 년치가 필요하다.
     * 같은 값을 KRX 에서 또 받는 건 낭비라, 여기서 krx_amt 로 옮겨 담는다 — 추가 API 호출 0회.
     * ★ <b>전종목</b>을 옮긴다(2026-07-31 부터 — 그 전엔 보유·관심 24종목만이었다).
     * prune 보다 <b>먼저</b> 해야 한다. */
    try {
        $amt = new KrxAmt($pdo);
        $amt->ensureTable();

        /* 어제 15:50 eod 크론이 넣어 둔 네이버 잠정치(src='n')와 지금 온 KRX 확정값의 괴리를
         * <b>덮어쓰기 전에</b> 잰다 — 덮고 나면 잠정값이 사라져 다시는 잴 수 없다.
         * 이 로그가 며칠 쌓이면 T+0 잠정 경로를 믿어도 되는지가 숫자로 판명된다. */
        $gap = $amt->provisionalGap();
        if ($gap) say(sprintf('잠정치 검증 — %s종목 · 거래대금 절대오차 중앙 %.3f%% · 1%% 초과 %d종목',
            number_format($gap[0]), $gap[1], $gap[2]));

        $moved = $amt->syncFromKrxDaily(null);
        if ($moved) say("장기 원장 이관 — " . number_format($moved) . "행 전종목 (krx_amt · API 0회)");

        /* 확정값이 들어왔으니 그 날짜부터의 신호 캐시를 지우고 이 날짜는 바로 다시 계산한다.
         * (잠정치로 계산해 둔 판정이 확정값과 다를 수 있다 — 화면이 8초 내지 않게 여기서 미리) */
        if ($moved) {
            $amt->invalidateSurge($dd);
            $t1 = microtime(true);
            $sg = $amt->rebuildSurge($dd);
            say(sprintf('신고가 신호 재계산 — %s 신호 %d종목 (%.1f초)', $dd, count($sg['rows']), microtime(true) - $t1));
        }
        /* ★ 일일 결산 재계산 (2026-09-07) — 확정 종가가 잠정치를 덮었으니 그 날짜부터 다시 잰다(지수도 최근 20일 덮음). */
        if ($moved) {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/value.php';
                pf_value_ensure($pdo);
                $ki = new KrxIndex($pdo);
                $ki->ensureTable();
                $ki->collect(1, 20);
                $vr = pf_value_rebuild($pdo, null, $dd, null, 'k');
                say(sprintf('일일 결산 재계산 — %s 부터 거래일 %d · %d행', $dd, $vr['days'], $vr['rows']));
            } catch (Throwable $e) {
                say('일일 결산 재계산 실패(무시): ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        say('장기 원장 이관 실패(무시하고 계속): ' . $e->getMessage());
    }

    $gone = $krx->prune($keep);
    if ($gone) say("오래된 기준일 정리 — {$gone}행 삭제 (최근 {$keep}일만 남김)");
}

// ── 전종목 시세 메우기 (job=quotes) ────────────────────────────────────
/**
 * all_stock_info 의 낡은 시세를 네이버 실시간 값으로 메운다.
 *
 * 장 마감 뒤 all_stock_info 를 갱신하는 것은 NXT 크론인데 <b>604종목뿐</b>이다(전체의 21.9%).
 * 나머지 2,154종목은 <b>15:30 종가조차 못 받고</b> 마지막 장중 값에 멈춘다 —
 * 실측(2026-07-29) 2,022종목이 15:05 에 멈춰 있었고, 현대차우는 실제 종가와 2% 어긋나 있었다.
 * 재무분석 스크리너도 이 값으로 PER·시총을 내므로 그대로 두면 조용히 틀린 숫자가 나간다.
 *
 * 판정 규칙은 NaverFinanceAPI::refreshQuotes() 한 군데에 있다 — 마감 뒤에는 uDate 가
 * 오늘 15:30 이전인 종목만 손대므로 NXT 갱신분(uDate ≥ 15:31)과 부딪히지 않는다.
 *
 * 100종목씩 묶어 부르므로 전종목이 28회다 — 실측 5종목 17ms 라 몇 초면 끝난다.
 */
function job_quotes(PDO $pdo, bool $force): void
{
    $api = new NaverFinanceAPI();
    $t0  = microtime(true);
    $r   = $api->refreshQuotes($pdo, [], $force);

    say(sprintf('대상 %s종목 · 낡음 %s · 받음 %s · 갱신 %s (%.1f초)%s',
        number_format($r['target']), number_format($r['stale']),
        number_format($r['fetched']), number_format($r['updated']),
        microtime(true) - $t0,
        $r['stale'] === 0 ? ' — 전부 신선해 부르지 않았습니다' : ''));

    if ($r['stale'] > 0 && $r['fetched'] === 0) say('★ 네이버에서 받아오지 못했습니다 — 기존 값을 그대로 둡니다.');
}

// ── 일봉 이력 (job=daily) ──────────────────────────────────────────────
/**
 * 시장 신호(급등락·거래량 급증·이동평균·RSI·52주 위치)의 <b>백데이터</b>를 쌓는다.
 *
 * 왜 크론이 필요한가: 지표는 전부 이력이 있어야 나오는데 이 레포의 시세 원천에는 이력이 없다
 * (all_stock_info 는 스냅샷 + TRUNCATE 3곳 · krx_daily 는 최근 10일만 · pf_sim_data 는 등록 종목만).
 * 화면이 열릴 때마다 네이버를 부르는 대안은 17종목이면 새로고침 한 번에 17콜이라 429 를 부른다.
 *
 * ★ 대상은 <b>보유 + 관심 종목만</b>(현재 14종목). 전 종목이면 3년치 200만 행 + 호출 2,700회다.
 * ★ 증분이다 — 마지막 저장일부터 오늘까지만 받는다. 처음이면 days 일(기본 1100 ≈ 3년).
 *   마지막 날을 <b>포함해</b> 다시 받는다: 장중에 한 번 받아 뒀으면 그 날 봉이 미완성이라 덮어써야 한다.
 * ★ 멱등이다 — (종목,일자) PK + UPSERT 라 겹쳐 받아도, 두 번 돌아도 안전하다.
 * ★ 마감(15:30) 뒤에 돌려야 그날 종가·거래량이 확정값으로 들어온다.
 */
function job_daily(PDO $pdo, int $days, int $gap, float $start, int $secs, bool $full): void
{
    $pf = new Pf($pdo);
    $pf->ensureTables();
    $api = new NaverFinanceAPI();

    $codes = $pf->signalCodes();
    if (!$codes) { say('보유·관심 종목이 없습니다 — 받을 것이 없습니다.'); return; }

    say(sprintf('대상 %d종목 · %s · 간격 %dms', count($codes),
        $full ? "통째 재수집({$days}일)" : '증분(없으면 ' . $days . '일)', $gap));

    $got = $skip = $rows = $fail = 0;
    $today = date('Y-m-d');

    foreach ($codes as $code) {
        if ($secs > 0 && microtime(true) - $start > $secs) {
            say(sprintf('예산 %d초 도달 — 여기서 멈춥니다. 다시 부르면 이어받습니다.', $secs));
            break;
        }
        $lastD = $full ? null : $pf->dailyLastDate($code);
        if ($lastD !== null && $lastD >= $today) { $skip++; continue; }

        $from = ($lastD === null)
            ? date('Ymd', strtotime("-{$days} days"))
            : date('Ymd', strtotime($lastD));          // 마지막 날 포함 — 미완성 봉을 덮어쓴다
        $r = $api->getDailyOhlcRange($code, $from, date('Ymd'));
        if (isset($r['error'])) { $fail++; say("  {$code} 실패 — {$r['error']}"); continue; }

        $n = $pf->dailyUpsert($code, $r['success'] ?? []);
        $rows += $n;
        $got++;
        if ($gap > 0) usleep($gap * 1000);
    }

    say(sprintf('받음 %d종목 · %s행 저장 · 이미최신 %d%s',
        $got, number_format($rows), $skip, $fail ? " · 실패 {$fail}" : ''));

    $st = $pf->dailyStatus();
    say(sprintf('현황 — %d종목 %s행 (%s ~ %s)',
        (int)$st['codes'], number_format((int)$st['rows_n']),
        (string)($st['from_d'] ?? '-'), (string)($st['to_d'] ?? '-')));
}

/* ── 6개월 고가·저가 ────────────────────────────────────────────────────
 *
 * 스크리너의 「고점대비 · 저점대비」열이 쓰는 값이다.
 *
 * ★ 이 API 에는 묶음 호출이 없다 — 종목마다 한 번씩이라 전종목이 2,766회다.
 *   화면에서 할 수 없는 이유가 그것이다.
 *
 * ★★ 그래서 <b>여러 번에 나눠 돈다</b>. 한 번에 끝내려고 간격을 20ms 로 줄였더니
 *   111초에 끝나긴 했는데 그게 초당 25건이었다 — 한 IP 에서 낼 속도가 아니다.
 *   간격을 100ms(초당 8건)로 늘리면 전종목이 6분이고, 그건 웹 요청 한 번에 안 들어간다
 *   (이 레포에서 실측된 상한은 106초).
 *
 *   오늘 이미 받은 종목은 건너뛰므로 <b>크론을 여러 번 걸어 두면 알아서 이어받는다</b>.
 *   다 끝난 뒤의 호출은 1초 만에 빠지므로 남는 슬롯은 공짜다.
 *
 * 장 마감 뒤에 돌리는 것이 맞다 — 그래야 오늘 고가·저가까지 들어간다.
 */
/* 매일 몫 — 오늘 고·저 한 줄 추가 + 6개월 집계 재계산 + 오래된 행 정리.
 * 셋 다 합쳐 몇 초다. 크론 타임아웃(30초)에 넉넉히 들어간다. */
function job_range_day(PDO $pdo, int $months): void
{
    $api = new NaverFinanceAPI();

    $t0 = microtime(true);
    $r  = $api->appendTodayRange($pdo, $months);
    say(sprintf('오늘 고·저 — 대상 %s종목 · 받음 %s · 저장 %s · 값없음 %s (%.1f초 · 호출 %d회)',
        number_format($r['target']), number_format($r['fetched']),
        number_format($r['saved']), number_format($r['skipped']),
        microtime(true) - $t0, (int)ceil($r['target'] / 100)));

    if ($r['fetched'] === 0)  say('★ 네이버에서 받지 못했습니다 — 기존 값을 그대로 둡니다.');
    // 장 전(PREOPEN)·휴장일에는 고·저가 0 으로 온다. 잘못 걸어 둔 시각을 알아채야 한다.
    if ($r['fetched'] > 0 && $r['saved'] === 0) {
        say('★ 저장할 값이 없습니다 — 장 마감(15:30) 전이거나 휴장일입니다. 크론 시각을 확인하세요.');
    }

    $t1 = microtime(true);
    $u  = $api->syncPriceRange($pdo, $months);
    say(sprintf('%d개월 집계 재계산 — %s종목 (%.1f초)', $months, number_format($u), microtime(true) - $t1));

    $t2 = microtime(true);
    $d  = $api->pruneDailyRange($pdo);
    if ($d > 0) say(sprintf('오래된 일별 행 %s개 정리 (%.1f초)', number_format($d), microtime(true) - $t2));
}

/* 최초 1회(씨 뿌리기) — 일봉을 종목마다 받아 일별 원본을 채운다.
 * 크론에 걸 것이 아니다(6분 · 타임아웃 30초). SSH 로 한 번 돌리는 용도다. */
function job_range(PDO $pdo, int $months, bool $force, float $start, int $secs, int $gapMs): void
{
    $api = new NaverFinanceAPI();
    $t0  = microtime(true);
    $left = $secs > 0 ? max(1, $secs - (int)(microtime(true) - $start)) : 0;

    $r = $api->refreshPriceRange($pdo, [], $months, $force, $left, function ($done, $todo, $saved, $failed) {
        say("  … {$done}/{$todo} (저장 {$saved} · 실패 {$failed})");
    }, $gapMs * 1000);

    say(sprintf('대상 %s종목 · 오늘 아직 안 받은 것 %s · 저장 %s · 실패 %s · 남음 %s (%.0f초)',
        number_format($r['target']), number_format($r['todo']),
        number_format($r['saved']), number_format($r['failed']), number_format($r['remain']),
        microtime(true) - $t0));

    if ($r['todo'] === 0)   say('오늘 몫은 이미 다 받았습니다 — 다시 받으려면 force=1 을 붙이세요.');
    if ($r['remain'] > 0)   say('★ 남았습니다 — 다시 실행하면 이어받습니다.');
    // 실패가 몇 건 있는 건 상장폐지·거래정지라 정상이다. 절반을 넘으면 그건 차단이다.
    if ($r['failed'] > 0 && $r['failed'] > $r['saved']) say('★ 실패가 성공보다 많습니다 — 네이버가 막았을 수 있습니다.');
}

// ── 분기 재무제표 ──────────────────────────────────────────────────────
// 손익은 누적(YTD)으로 저장된다 — Dart::REPRT_INFO 주석 참조.
function job_quarter(Dart $dart, int $from, int $to, int $budget, float $start, int $secs): void
{
    $used = 0;
    for ($y = $to; $y >= $from; $y--) {
        foreach (Dart::REPRT_QUARTERS as $rc) {
            if (over($start, $secs))              { say('시간 예산 초과 — 멈춥니다.'); return; }
            if ($budget > 0 && $used >= $budget)  { say('호출 예산 소진 — 멈춥니다.'); return; }

            $t0 = microtime(true);
            try {
                $r = $dart->collectFinancials($y, $rc);
            } catch (Throwable $e) {
                say("{$y} " . Dart::reprtName($rc) . ' — 실패: ' . $e->getMessage());
                // 020 = 일일 한도 초과. 더 돌아도 소용없다.
                if (strpos($e->getMessage(), '020') !== false) return;
                continue;
            }
            // 호출 수는 청크 수만큼 늘어난다 (100종목/회)
            $used += (int)ceil($dart->corpCodeCount() / Dart::MULTI_MAX);
            say(sprintf('%d %-4s 종목 %5s · 저장 %5s (%.1f초)',
                $y, Dart::reprtName($rc), number_format($r['companies']), number_format($r['saved']),
                microtime(true) - $t0));
        }
    }
    say('분기 수집 완료.');
}

// ── 연도별 주식수 ──────────────────────────────────────────────────────
// 다중회사 API 가 없어 종목마다 1회다. 이미 채운 행은 SQL 에서 빠지므로 반복 실행하면 이어진다.
function job_shares(Dart $dart, int $from, int $to, int $budget, float $start, int $secs): void
{
    $used = 0;
    for ($y = $to; $y >= $from; $y--) {
        if (over($start, $secs))             { say('시간 예산 초과 — 멈춥니다.'); return; }
        if ($budget > 0 && $used >= $budget) { say('호출 예산 소진 — 멈춥니다.'); return; }

        $limit = ($budget > 0) ? max(1, $budget - $used) : 0;

        $t0 = microtime(true);
        $r  = $dart->collectShares($y, $limit, true, function ($done, $total, $filled) use ($y) {
            say("  {$y} … {$done}/{$total} (채움 {$filled})");
        });
        $used += $r['done'];

        say(sprintf('%d 주식수 — 처리 %5s · 채움 %5s · 값없음 %4s · 남음 %5s (%.0f초)',
            $y, number_format($r['done']), number_format($r['filled']),
            number_format($r['empty']), number_format($r['remain']), microtime(true) - $t0));

        // 남은 게 있는데 멈춘 것은 예산·한도 때문이다. 다음 해로 넘어가면 안 된다.
        if ($r['remain'] > 0) { say('이 해가 아직 남았습니다 — 다시 실행하면 이어받습니다.'); return; }
    }
    say('주식수 수집 완료.');
}

// ── 연도별 배당 (job=alot · 2026-09-02) ────────────────────────────────
/**
 * DPS·현금배당수익률을 전종목으로 채운다 (stock_fundamental).
 *
 * alotMatter 는 다중회사 버전이 없어 종목마다 1회 — 주식수(job=shares)와 같은 이어받기 설계다.
 * 이미 채운 (종목,연도)는 SQL 에서 빠지므로 budget= 으로 조각내 반복 실행하면 이어진다.
 * 전종목 11년 ≈ 28,000콜이라 하루 한도(20,000)를 넘는다 — <b>이틀에 나눠</b> 돌린다.
 * 매일 몫(새 사업보고서 종목)은 job=fresh 안에 들어 있다.
 */
function job_alot(Dart $dart, int $from, int $to, int $budget, float $start, int $secs): void
{
    $used = 0;
    for ($y = $to; $y >= $from; $y--) {
        if (over($start, $secs))             { say('시간 예산 초과 — 멈춥니다.'); return; }
        if ($budget > 0 && $used >= $budget) { say('호출 예산 소진 — 멈춥니다.'); return; }

        $limit = ($budget > 0) ? max(1, $budget - $used) : 0;

        $t0 = microtime(true);
        $r  = $dart->collectAlot($y, $limit, true, function ($done, $total, $filled) use ($y) {
            say("  {$y} … {$done}/{$total} (배당있음 {$filled})");
        });
        $used += $r['done'];

        say(sprintf('%d 배당 — 처리 %5s · 배당있음 %5s · 무배당·값없음 %4s · 남음 %5s (%.0f초)',
            $y, number_format($r['done']), number_format($r['filled']),
            number_format($r['empty']), number_format($r['remain']), microtime(true) - $t0));

        if ($r['remain'] > 0) { say('이 해가 아직 남았습니다 — 다시 실행하면 이어받습니다.'); return; }
    }
    say('배당 수집 완료.');
}

// ── 정기보고서 접수일 원장 (job=rcept · 2026-08-02) ─────────────────────
/**
 * DART 공시목록(list.json)에서 <b>정기보고서의 실제 접수일</b>을 모은다.
 *
 * 왜: SUE(이익 서프라이즈) 신호일을 법정 마감일로 근사하면 늦게 내는 2~3%에
 * 선견 편향이 생기고, 일찍 내는 회사의 드리프트 앞부분을 놓친다.
 * 접수일이 있으면 「공시 다음 거래일 진입」을 정확히 잰다 (백테스트·어닝 탭 공용).
 *
 * 저장: dart_rcept — rcept_no PK 라 INSERT IGNORE 멱등. 정정공시([기재정정])도
 * 별도 rcept_no 로 들어오므로 <b>원본 접수일 = MIN(rcept_dt)</b> 로 읽는 것이 소비자 규칙.
 * ★분기보고서 월이 03/09 가 아니면(비12월 결산 ~2%) 1Q·3Q 를 가릴 수 없어 reprt_code NULL —
 *   조인에서 저절로 빠진다(백테스트의 명시된 한계와 같은 자리).
 */
function rcept_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dart_rcept (
          rcept_no   CHAR(14)     NOT NULL PRIMARY KEY,
          corp_code  CHAR(8)      NOT NULL,
          stock_code VARCHAR(10)  NOT NULL,
          rcept_dt   DATE         NOT NULL,
          report_nm  VARCHAR(150) NOT NULL,
          bsns_year  SMALLINT     NULL,
          reprt_code CHAR(5)      NULL,
          KEY ix_code (stock_code, bsns_year, reprt_code),
          KEY ix_dt (rcept_dt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** list.json 한 페이지 — 실패는 예외로 (호출부가 세고 멈춘다) */
function rcept_page(string $bgn, string $end, string $cls, string $ty, int $page): array
{
    $url = 'https://opendart.fss.or.kr/api/list.json?' . http_build_query([
        'crtfc_key' => DART_API_KEY, 'bgn_de' => $bgn, 'end_de' => $end,
        'corp_cls' => $cls, 'pblntf_detail_ty' => $ty,
        'page_no' => $page, 'page_count' => 100,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("curl: $e"); }
    curl_close($ch);
    $j = json_decode((string)$body, true);
    if (!is_array($j)) throw new RuntimeException('list.json 응답이 JSON 이 아닙니다');
    if (($j['status'] ?? '') === '013') return ['total_page' => 0, 'list' => []];   // 결과 없음
    if (($j['status'] ?? '') !== '000') throw new RuntimeException('list.json status ' . ($j['status'] ?? '?') . ' ' . ($j['message'] ?? ''));
    return $j;
}

function job_rcept(PDO $pdo, string $fromYmd, string $toYmd, int $budget, float $start, int $secs): void
{
    rcept_table($pdo);
    $ins = $pdo->prepare("
        INSERT IGNORE INTO dart_rcept (rcept_no, corp_code, stock_code, rcept_dt, report_nm, bsns_year, reprt_code)
        VALUES (?,?,?,?,?,?,?)");

    $cur  = new DateTimeImmutable(substr($fromYmd, 0, 4) . '-' . substr($fromYmd, 4, 2) . '-01');
    $stop = new DateTimeImmutable(substr($toYmd, 0, 4) . '-' . substr($toYmd, 4, 2) . '-' . substr($toYmd, 6, 2));
    $calls = 0; $saved = 0; $seen = 0;

    while ($cur <= $stop) {
        $wEnd = min($cur->modify('last day of this month'), $stop);
        foreach (['Y', 'K'] as $cls) {                       // 코스피·코스닥만 (백테스트 모집단과 동일)
            foreach (['A001', 'A002', 'A003'] as $ty) {      // 사업·반기·분기보고서
                for ($page = 1; $page <= 40; $page++) {
                    if ($budget > 0 && $calls >= $budget) { say("호출 예산 도달 — 다음 실행: from=" . $cur->format('Ymd')); return; }
                    if (over($start, $secs))               { say("시간 예산 도달 — 다음 실행: from=" . $cur->format('Ymd')); return; }
                    try {
                        $j = rcept_page($cur->format('Ymd'), $wEnd->format('Ymd'), $cls, $ty, $page);
                    } catch (Throwable $e) {
                        say($cur->format('Y-m') . " $cls $ty p$page 실패: " . $e->getMessage());
                        break;   // 이 (창×종류)만 접고 다음으로 — 멱등이라 다음 실행이 메운다
                    }
                    $calls++;
                    foreach ($j['list'] ?? [] as $it) {
                        $seen++;
                        $stk = trim((string)($it['stock_code'] ?? ''));
                        if ($stk === '') continue;           // 상장 종목만
                        $y = null; $rc = null;
                        if (preg_match('/(사업|반기|분기)보고서\s*\((\d{4})\.(\d{2})\)/u', (string)$it['report_nm'], $m)) {
                            $y = (int)$m[2];
                            $mm = (int)$m[3];
                            $rc = match ($m[1]) {
                                '사업' => Dart::REPRT_ANNUAL,
                                '반기' => Dart::REPRT_H1,
                                // 분기는 월로 1Q/3Q 를 가른다 — 03/09 가 아니면 비12월 결산이라 미상(NULL)
                                '분기' => ($mm === 3 ? Dart::REPRT_Q1 : ($mm === 9 ? Dart::REPRT_Q3 : null)),
                            };
                        }
                        $dt = (string)$it['rcept_dt'];
                        $ins->execute([
                            (string)$it['rcept_no'], (string)$it['corp_code'], $stk,
                            substr($dt, 0, 4) . '-' . substr($dt, 4, 2) . '-' . substr($dt, 6, 2),
                            mb_substr((string)$it['report_nm'], 0, 150),
                            $rc !== null ? $y : null, $rc,
                        ]);
                        if ($ins->rowCount() > 0) $saved++;
                    }
                    if ($page >= (int)($j['total_page'] ?? 0)) break;
                    usleep(100000);   // 포털 예의
                }
            }
        }
        $cur = $cur->modify('first day of next month');
    }
    $n = $pdo->query("SELECT COUNT(*), MIN(rcept_dt), MAX(rcept_dt) FROM dart_rcept")->fetch(PDO::FETCH_NUM);
    say(sprintf('접수일 수집 — 호출 %d · 훑음 %s건 · 새로 %s건 · 원장 %s행 (%s ~ %s)',
        $calls, number_format($seen), number_format($saved), number_format((int)$n[0]), $n[1], $n[2]));
}

// ── 실행 ───────────────────────────────────────────────────────────────
$from = (int)($_GET['from'] ?? 0);
$to   = (int)($_GET['to']   ?? 0);

switch ($job) {
    // ── 매일 새벽: DART 최신 슬롯 갱신 (+ 최근 3주 접수일 — 어닝 탭·SUE 신호일용)
    case 'fresh':
        job_fresh($dart, (int)($_GET['slots'] ?? 5), $budget, $start, $secs);
        say('── 접수일 원장 (최근 21일)');
        try {
            job_rcept($pdo, date('Ymd', strtotime('-21 days')), date('Ymd'), 0, $start, $secs ?: 300);
        } catch (Throwable $e) {
            say('접수일 수집 실패(무시하고 계속): ' . $e->getMessage());
        }
        /* ★ 실적 신호 알림 (2026-08-02) — 방금 갱신된 재무로 보유 어닝쇼크·관심 서프라이즈를
         * 판정해 새로 생긴 것만 Pushover 로 쏜다. 판정은 화면(lib/sue.php)과 같은 단일본. */
        say('── 실적 신호 알림');
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/alert.php';
            $al = pf_alert_fresh($pdo);
            say($al ? count($al) . '건 발송: ' . implode(' / ', array_slice($al, 0, 3)) : '새 신호 없음 — 발송 안 함');
        } catch (Throwable $e) {
            say('알림 실패(무시하고 계속): ' . $e->getMessage());
            if (function_exists('pf_alert_fail')) pf_alert_fail('fresh', $e);   // §2.5 규칙 3 — 삼킨 예외는 직접 알림
        }
        break;

    /* ── 순이익 결측 보수 (일회성 배치 · 매일 몫은 fresh 안에 있다)
     *   budget= 로 조각내 반복 실행. y=·rc= 로 특정 연도·보고서만도 가능 */
    case 'nifix':
        job_nifix($dart, (int)($_GET['budget'] ?? 0), (int)($_GET['y'] ?? 0),
                  (string)($_GET['rc'] ?? ''), $start, $secs);
        break;

    // ── 매일 오전·오후: KRX 최근 거래일 시세·상장주식수
    case 'krx':
        job_krx($pdo, (int)($_GET['keep'] ?? 10));
        break;

    /* ── 장 마감 뒤 한 묶음 (크론 한 줄) ─────────────────────────────────
     *
     * quotes 와 range 를 따로 걸면 크론 항목이 둘이 된다. 둘 다 마감 직후에 돌아야 하고
     * 합쳐도 <b>3초</b>라(실측 1.1 + 1.5) 나눌 이유가 없다.
     * 같은 시각에 도는 것을 한 줄로 묶는 것이 이 잡의 전부다.
     *
     * 순서가 있다: 시세를 먼저 메워야 화면의 현재가와 고·저가 같은 시점을 본다.
     */
    case 'eod':
        say('── 시세 메우기');
        job_quotes($pdo, !empty($_GET['force']));
        say('── 오늘 고·저 + 6개월 집계');
        job_range_day($pdo, (int)($_GET['months'] ?? NaverFinanceAPI::RANGE_MONTHS));
        /* 일봉 이력도 같은 시각(마감 뒤)에 받아야 그날 종가·거래량이 확정값이다.
         * 증분이라 종목당 한 콜, 14종목이면 몇 초다 — 크론 줄을 늘릴 이유가 없다. */
        say('── 일봉 이력 (시장 신호 백데이터)');
        job_daily($pdo, (int)($_GET['days'] ?? 1100), 150, $start, $secs, false);
        /* ★ 오늘 행 잠정 적재 (2026-07-31 · 퀀트 거래대금 신고가)
         * KRX 확정값은 T+1 이라 「당일 마감 후에 본다」는 요건을 이 잠정치가 채운다.
         * 방금 quotes 가 메워 둔 all_stock_info 스냅샷을 krx_amt 오늘 행으로 옮긴다(src='n').
         * 다음날 13:05 dart_krx 가 확정값으로 덮어쓰고, 덮기 직전 괴리를 로그로 남긴다.
         * 추가 API 호출 0회 · 휴장일은 uDate 가 낡아 저절로 0건. */
        say('── 퀀트 원장 잠정 적재 (오늘 거래대금)');
        try {
            $amt = new KrxAmt($pdo);
            $amt->ensureTable();
            $np = $amt->provisionalFromSnapshot();
            say($np ? number_format($np) . '종목 잠정 저장 (src=n · 내일 13:05 KRX 확정값으로 대체)'
                    : '저장할 잠정치가 없습니다 — 휴장일이거나 스냅샷이 낡았습니다.');
            /* 오늘 신호를 여기서 미리 계산해 둔다 — 화면이 8초 내지 않게 (krx_surge 캐시) */
            if ($np) {
                $today = date('Y-m-d');
                $amt->invalidateSurge($today);
                $t1 = microtime(true);
                $sg = $amt->rebuildSurge($today);
                say(sprintf('신고가 신호 계산 — 오늘 %d종목 (%.1f초)', count($sg['rows']), microtime(true) - $t1));
            }
        } catch (Throwable $e) {
            say('잠정 적재 실패(무시하고 계속): ' . $e->getMessage());
        }
        /* ★ 지수 + 일일 결산 (2026-09-07 · stock/lib/value.php)
         *   방금 krx_amt 에 들어간 오늘 잠정 종가로 포트폴리오별 결산 행(pf_value_daily)을 쓴다 — 현황 카드 미니 그래프의 원천.
         *   지수(KOSPI·KOSDAQ)는 네이버에서 최근 20일을 받아 덮는다(당일 종가 포함 · 2콜). 내일 13:05 확정값이 오면 job=krx 가 다시 잰다.
         *   휴장일은 krx_amt 에 오늘 행이 없어 저절로 0건. 실패해도 마감 묶음은 계속 간다(캐시일 뿐이다). */
        say('── 지수 · 일일 결산');
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/value.php';
            pf_value_ensure($pdo);
            $ki = new KrxIndex($pdo);
            $ki->ensureTable();
            $ir = $ki->collect(1, 20);
            say(sprintf('지수 — KOSPI·KOSDAQ %d행 갱신 (최신 %s · %d콜)', $ir['rows'], $ir['last'] ?? '-', $ir['calls']));
            $vr = pf_value_rebuild($pdo, null, date('Y-m-d'), null, 'e');
            say($vr['rows'] ? sprintf('결산 — %s · 포트폴리오 %d행', $vr['to'], $vr['rows'])
                            : '결산 — 오늘 거래일 행이 없어 건너뜀 (휴장일이거나 잠정 적재 0건)');
        } catch (Throwable $e) {
            say('일일 결산 실패(무시하고 계속): ' . $e->getMessage());
        }
        /* ★ 데이터 레벨 감시 (2026-08-02)
         * "크론은 성공했는데 데이터가 안 들어온" 케이스는 실행 감시(cron_job.php 중앙
         * 실패 알림)로는 못 잡는다 — 마감 묶음이 끝난 시점에 all_stock_info 의 당일
         * 갱신률을 직접 재서, 일부만 갱신됐으면(부분 응답·네이버 차단 등) 경고를 쏜다.
         * 갱신 0건 = 휴장일(주말·공휴일)이므로 조용히 넘어간다 — 오탐 방지. */
        try {
            $r = $pdo->query(
                "SELECT COUNT(*) AS t, COALESCE(SUM(DATE(uDate) = CURDATE()), 0) AS f
                   FROM all_stock_info"
            )->fetch(PDO::FETCH_ASSOC);
            $tot = (int)$r['t']; $frs = (int)$r['f'];
            $pct = $tot > 0 ? round($frs / $tot * 100, 1) : 0.0;
            say(sprintf('── 당일 갱신률 점검 — %s/%s종목 (%.1f%%)',
                number_format($frs), number_format($tot), $pct));
            if ($frs > 0 && $pct < 80 && class_exists('Notify')) {
                Notify::send(
                    "마감 후 종가 갱신률이 낮습니다 — "
                    . number_format($frs) . "/" . number_format($tot) . "종목 ({$pct}%)\n"
                    . "네이버 부분 응답이나 시세 크론 실패 가능성. 화면 종가가 낡았을 수 있습니다.",
                    "https://economist.kr/cron_job.php?task=dart_status&k=econ-cron-j7k2",
                    ['title' => '⚠️ 종가 갱신률 저조', 'priority' => 1]
                );
                say('  → 경고 알림(Pushover) 발송');
            }
        } catch (Throwable $e) {
            say('갱신률 점검 실패(무시): ' . $e->getMessage());
        }
        /* ★ 수급 신호 알림 (2026-08-02) — 방금 계산된 오늘 잠정 신호와 최근 박스 상태로
         * ①관심종목 트리거(돌파확인·계단지지) ②보유 계단관통↓ ③오늘 신규 매집형을
         * 새로 생긴 것만 Pushover 로 쏜다. 판정은 화면과 같은 단일본(boxStatusMany 등). */
        say('── 수급 신호 알림');
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/alert.php';
            $al = pf_alert_eod($pdo);
            say($al ? count($al) . '건 발송: ' . implode(' / ', array_slice($al, 0, 3)) : '새 신호 없음 — 발송 안 함');
        } catch (Throwable $e) {
            say('알림 실패(무시하고 계속): ' . $e->getMessage());
            if (function_exists('pf_alert_fail')) pf_alert_fail('eod', $e);     // §2.5 규칙 3 — 삼킨 예외는 직접 알림
        }
        /* ★ 마감 변동 요약 (2026-08-31) — 관심·단타·보유 종목 중 오늘 |등락률| 이 Thr::EOD_MOVE_PCT 를
         *   넘은 것을 한 건으로 묶어 쏜다. 방금 메운 all_stock_info 를 읽기만 한다(새 수집 없음).
         *   위 수급 신호(이벤트·신규만)와 «묻는 것»이 달라 제목을 가른다. 미리보기 = job=move. */
        say('── 마감 변동 요약');
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/alert.php';
            $mv = pf_alert_move($pdo);
            say($mv ? '발송: ' . $mv[0] : '임계 넘은 종목 없음 — 발송 안 함');
        } catch (Throwable $e) {
            say('변동 요약 실패(무시하고 계속): ' . $e->getMessage());
            if (function_exists('pf_alert_fail')) pf_alert_fail('eod', $e);
        }
        break;

    /* ── 포트폴리오 일일 결산 백필·재생성 (수동 · 2026-09-07) — &from=YYYY-MM-DD [&to=] [&fid=] [&idx=1 지수 백필]
     *   표(pf_value_daily)는 캐시라 몇 번 돌려도 같은 값으로 덮인다. 매일 몫은 eod(오늘)·krx(확정 재계산) 안에 있다.
     *   idx=1 이면 지수를 from 까지 되짚어 받는다(60행/콜 · 4년 ≈ 34콜) — 없으면 최근 20일만. */
    case 'pfvalue':
        require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/value.php';
        pf_value_ensure($pdo);
        $vFrom = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-60 days')));
        $vTo   = (string)($_GET['to'] ?? date('Y-m-d'));
        $vFid  = (isset($_GET['fid']) && $_GET['fid'] !== '') ? (int)$_GET['fid'] : null;
        $ki = new KrxIndex($pdo);
        $ki->ensureTable();
        if (!empty($_GET['idx'])) {
            $ir = $ki->backfill($vFrom);
            say(sprintf('지수 백필 — %d콜 · %d행 (%s 까지)', $ir['calls'], $ir['rows'], $vFrom));
        } else {
            $ir = $ki->collect(1, 20);
            say(sprintf('지수 최근 — %d행 (최신 %s)', $ir['rows'], $ir['last'] ?? '-'));
        }
        $t1 = microtime(true);
        $vr = pf_value_rebuild($pdo, $vFid, $vFrom, $vTo, 'b');
        say(sprintf('결산 %s ~ %s — 거래일 %d · %d행 (%.1f초)%s', $vr['from'], $vr['to'], $vr['days'], $vr['rows'],
            microtime(true) - $t1, $vFid !== null ? " · 포트폴리오 {$vFid}" : ''));
        break;
    /* ── 마감 변동 요약 미리보기 — 보내지 않고 본문만 찍는다(pf_alert_log 도 안 적는다). 실제 발송은 eod 끝. */
    case 'move':
        require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/alert.php';
        $mv = pf_alert_move($pdo, true);
        if ($mv) { say('[미리보기 · 보내지 않음] 📊 마감 변동'); foreach ($mv as $l) say('  | ' . $l); }
        else say('임계 넘은 종목 없음 (오늘 갱신된 시세 기준 · 휴장일이면 늘 0건)');
        break;

    /* ── 일봉 이력만 따로 (시장 신호 백데이터) ───────────────────────────
     *   job=daily              증분 — eod 가 이걸 포함한다
     *   job=daily&full=1       통째 재수집 (씨 뿌리기 · 최초 1회)
     *   job=daily&days=1100    처음 받을 때 몇 일치까지 (기본 3년)
     * 종목당 1콜이고 대상이 보유+관심(십여 종목)뿐이라 간격을 넉넉히 줘도 몇 초다. */
    case 'daily':
        job_daily($pdo, (int)($_GET['days'] ?? 1100), max(0, (int)($_GET['gap'] ?? 150)),
                  $start, $secs, !empty($_GET['full']));
        break;

    // ── 장 마감 뒤: NXT 가 못 훑는 종목의 시세를 메운다 (eod 가 이걸 포함한다)
    case 'quotes':
        job_quotes($pdo, !empty($_GET['force']));
        break;

    // ── 장 마감 뒤: 6개월 고가·저가 (스크리너의 고점대비·저점대비)
    /* 기본(매일)은 <b>이어붙이기</b>다 — 묶음 호출이라 28회로 끝난다.
     * full=1 을 붙였을 때만 일봉을 통째로 다시 받는다(주 1회). */
    case 'range':
        $mon = (int)($_GET['months'] ?? NaverFinanceAPI::RANGE_MONTHS);
        if (empty($_GET['full'])) {
            say("최근 {$mon}개월 고가·저가 — 오늘 고·저 이어붙이기 (묶음 호출)");
            job_range_day($pdo, $mon);
            break;
        }
        /* full=1 은 <b>최초 1회 씨 뿌리기</b>다. 크론에 걸 것이 아니다 —
         * 종목마다 1회라 6분이고 크론 타임아웃은 30초다. SSH 로 한 번 돌린다. */
        $gap = max(0, (int)($_GET['gap'] ?? 100));      // 호출 간격(ms)
        say("최근 {$mon}개월 일별 고·저 <씨 뿌리기> — 간격 {$gap}ms"
            . ($secs > 0 ? " · 이번 실행 예산 {$secs}초" : ' · 예산 없음'));
        job_range($pdo, $mon, !empty($_GET['force']), $start, $secs, $gap);
        // 씨를 뿌린 만큼 집계도 새로 접어 준다 (안 하면 화면이 옛 집계를 본다)
        $api = new NaverFinanceAPI();
        say(sprintf('집계 재계산 — %s종목', number_format($api->syncPriceRange($pdo, $mon))));
        break;

    // ── 드라이런. fresh 가 어떤 슬롯을 고르는지만 보여 준다 (DART 를 부르지 않는다)
    //    ?job=slots&today=2026-08-15 처럼 날짜를 넣어 달력이 넘어갈 때를 미리 볼 수 있다
    case 'slots':
        $day = (string)($_GET['today'] ?? '');
        say('기준일 ' . ($day !== '' ? $day : date('Y-m-d')));
        foreach (fresh_slots((int)($_GET['slots'] ?? 5), $day !== '' ? $day : null) as $i => $s) {
            say(sprintf('  %d) %d %-4s  (대상기간 종료 %s)',
                $i + 1, $s['year'], Dart::reprtName($s['reprt']), $s['end']->format('Y-m-d')));
        }
        break;

    case 'quarter':
        // 사업연도 상한은 올해다 — 분기보고서는 그 해 안에 나온다 (연간과 다르다)
        if ($to   <= 0) $to   = (int)date('Y');
        if ($from <= 0) $from = Dart::MIN_QUARTER_YEAR;
        $from = max($from, Dart::MIN_QUARTER_YEAR);
        say("분기 재무제표 수집 — {$from}~{$to}년 · 보고서 3종");
        job_quarter($dart, $from, $to, $budget, $start, $secs);
        break;

    case 'shares':
        if ($to   <= 0) $to   = (int)date('Y') - 1;
        if ($from <= 0) $from = Dart::MIN_YEAR;
        say("연도별 주식수 수집 — {$from}~{$to}년");
        job_shares($dart, $from, $to, $budget, $start, $secs);
        break;

    /* ── 연도별 배당 (DPS·배당수익률) — 최초 백필은 SSH 조각 실행 (전종목 11년 ≈ 28,000콜 · 이틀)
     *   php cron/dart_collect.php job=alot from=2015 to=2025 budget=2500 sec=560 */
    case 'alot':
        if ($to   <= 0) $to   = (int)date('Y');
        if ($from <= 0) $from = Dart::MIN_YEAR;
        say("연도별 배당 수집 — {$from}~{$to}년");
        job_alot($dart, $from, $to, $budget, $start, $secs);
        break;

    /* ── 정기보고서 접수일 원장 ─────────────────────────────────────────
     *   매일분은 fresh 가 최근 21일을 같이 받는다 — 이 잡은 <b>과거 백필 전용</b>(SSH).
     *   php cron/dart_collect.php job=rcept from=20160101 sec=540   ← 끊기면 안내대로 이어받기 */
    case 'rcept':
        $rFrom = preg_replace('/[^0-9]/', '', (string)($_GET['from'] ?? '')) ?: '20160101';
        $rTo   = preg_replace('/[^0-9]/', '', (string)($_GET['to'] ?? ''))   ?: date('Ymd');
        say("정기보고서 접수일 수집 — {$rFrom} ~ {$rTo}");
        job_rcept($pdo, $rFrom, $rTo, $budget, $start, $secs);
        break;

    default:
        show_status($pdo, $dart);
}

say(sprintf('총 %.1f초', microtime(true) - $start));
?>
