<?php
/**
 * cron_job.php — 모든 크론의 <b>유일한 진입점</b> (레지스트리 + 디스패처)
 *
 * ══ 왜 이게 있나 ══════════════════════════════════════════════════════════
 * 크론은 cron-job.org(외부 URL 호출)에 등록돼 있고, 그 화면은 사람만 고칠 수 있다.
 * 그래서 예전에는 잡의 모드·옵션을 바꾸려면 <b>매번 크론 사이트에 들어가 URL을 고쳐야</b> 했다.
 *
 * 이 파일이 생긴 뒤로는 크론 사이트에 등록되는 URL이 <b>영원히 이것 하나</b>다:
 *     https://economist.kr/cron_job.php?task=<이름>&k=…
 *
 * ★ task 를 <b>k 보다 앞에</b> 둔다. cron-job.org 목록 화면은 URL을 47자쯤에서 자르는데,
 *   k 가 앞이면 전부 "…cron_job.php?k=econ-cron-j7…" 로 똑같이 보여 <b>어느 잡인지 구분이 안 된다</b>.
 *   task 가 앞이면 잘려도 이름이 보인다. (파라미터 순서는 동작에 영향 없다 — 읽기 편하라고 정한 규칙)
 *
 * 무엇을 어떤 옵션으로 부를지는 아래 TASKS 레지스트리(=코드=git)가 정한다.
 * 잡을 바꾸거나 옵션을 조절할 때 <b>크론 사이트는 건드리지 않는다</b>. 이 파일만 고친다.
 *
 * ══ 어떻게 도나 ═══════════════════════════════════════════════════════════
 * HTTP 로 다시 부르지 않고 <b>같은 요청 안에서 require</b> 한다. $_GET 을 레지스트리 값으로
 * 갈아끼운 뒤 타깃 파일을 읽으면, 타깃은 자기가 직접 호출된 것처럼 그대로 돈다
 * (토큰 검사도 통과한다 — 레지스트리가 그 토큰을 넣어 주기 때문).
 *
 * ★ bg(30초 우회)가 저절로 맞아떨어진다. env/cronbg.inc 는 자기호출 URL을
 *   <b>SCRIPT_NAME + 현재 $_GET</b> 으로 만드는데, SCRIPT_NAME 이 /cron_job.php 라서
 *   되돌아오는 요청도 이 디스패처를 거쳐 같은 task 로 라우팅된다:
 *
 *     cron-job.org
 *       └→ cron_job.php?task=etf_update&k=…          (레지스트리 주입 → require)
 *            └→ cron/keyword_collector.php           cron_bg_begin() 이 소켓을 던짐
 *                 └→ cron_job.php?…&task=etf_update&k=…&run=1   ← 다시 디스패처로
 *                      └→ cron/keyword_collector.php  run 모드로 본작업 완주
 *
 *   그래서 $_GET 에 <b>k 와 task 를 반드시 남겨 둔다</b>. 하나라도 빠지면 되돌아온 요청이
 *   토큰 검사에서 막히거나 라우팅되지 못해 <b>bg 작업이 조용히 사라진다</b>.
 *
 * ══ 쓰는 법 ═══════════════════════════════════════════════════════════════
 *   ?task=list&k=…              등록된 잡 전체 (크론식·파일·옵션·설명)
 *   ?task=etf_update&k=…        그 잡 실행
 *   ?task=etf_update&k=…&log=1  그 잡의 지난 bg 로그
 *   ?task=naver_stay&k=…&status=1   레지스트리에 없는 인자는 그대로 타깃에 전달된다
 *                                   (단 레지스트리가 정한 값이 우선 — 토큰·모드는 못 덮는다)
 *
 * CLI:  php cron_job.php task=dart_quarter from=2016 to=2026
 *
 * ══ 새 크론을 추가할 때 ═══════════════════════════════════════════════════
 *   1) cron/ 밑에 파일을 만든다 (env 는 $_SERVER['DOCUMENT_ROOT'] 기준으로 읽고,
 *      맨 위에서 require_once __DIR__.'/_boot.php' — CLI 대비)
 *   2) 30초를 넘을 수 있으면 env/cronbg.inc 를 쓴다 (자세한 건 CRON.md §2.2)
 *   3) 아래 TASKS 에 한 줄 추가하고 'bg' 를 맞춘다
 *   4) 크론 사이트에는 ?task=<이름>&k=… 만 등록한다 — 이후 변경은 전부 이 파일에서
 */

$CLI = (PHP_SAPI === 'cli');

// CLI 는 `task=etf_update` 꼴 인자를 $_GET 처럼 받는다
if ($CLI) {
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $_GET[$k] = $v; }
    }
}

/* 디스패처 토큰. 크론 사이트에 등록되는 URL의 ?k= 값이다.
 * 타깃들의 자체 토큰(ssk·key)은 레지스트리가 넣어 주므로 크론 URL에는 안 나온다. */
const CRON_JOB_KEY = 'econ-cron-j7k2';

/* ─────────────────────────────────────────────────────────────────────────
 * 레지스트리
 *
 *   file  웹 루트 기준 경로
 *   get   타깃에 넘길 $_GET (타깃의 자체 토큰 포함). <b>사용자 인자보다 우선</b>한다
 *   bg    true 면 &bg=1 을 붙인다 (30초를 넘는 잡)
 *   cron  실제 등록된 crontab 식. '' = 크론 미등록(수동·SSH 전용)
 *         ※ 진실은 cron-job.org 에 있다. 여기 값은 <b>사람이 읽기 위한 기록</b>이다 —
 *           크론 시각을 바꿨으면 여기도 같이 고친다 (CRON.md §1 이 이걸 본다)
 *   desc  한 줄 설명
 * ───────────────────────────────────────────────────────────────────────── */
const DART_KEY  = 'econ-dart-collect';
const KWC_KEY   = 'mysn1973!';
const NAVER_KEY = 'econ-naver-9x2k';

const TASKS = [

    // ── 매일 도는 것 ────────────────────────────────────────────────────
    'alert_check' => [
        'file' => 'cron/schedule_alert.php', 'bg' => false, 'cron' => '*/10 * * * *',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'check_alerts'],
        'desc' => '일정 사전 알림 — 15분 창의 미발송 알림을 Pushover 로',
    ],
    'alert_daily' => [
        'file' => 'cron/schedule_alert.php', 'bg' => false, 'cron' => '0 7 * * *',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'daily_summary'],
        'desc' => '당일 일정 요약 1건 발송',
    ],
    'dart_fresh' => [
        'file' => 'cron/dart_collect.php', 'bg' => true, 'cron' => '5 8 * * 1-5',
        'get'  => ['key' => DART_KEY, 'job' => 'fresh'],
        'desc' => 'DART 최신 5슬롯 재무제표 재수집 (78초 · bg 필수)',
    ],
    'market' => [
        'file' => 'market/crawl.php', 'bg' => false, 'cron' => '10 8 * * 1-6',
        'get'  => ['key' => 'econ-mkt-7x3k', 'report' => '1', 'notify' => '1', 'bg' => '1'],
        'desc' => '시장동향 수집 + 리포트 + Pushover (일요일 제외)',
    ],
    'news' => [
        'file' => 'cron/keyword_collector.php', 'bg' => false,
        'cron' => '5 6,8-10,12,14,16,18,20,22 * * *',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'news'],
        'desc' => '네이버 금융 섹션 뉴스 키워드 (매일 10회)',
    ],
    'stock_news' => [
        'file' => 'cron/keyword_collector.php', 'bg' => false,
        'cron' => '5 6-9,11,13,15,17,19 * * 1-5',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'stock_etf_news'],
        'desc' => '전종목 시세 갱신 + 급등주 키워드 (평일 9회 · all_stock_info 의 원천)',
    ],
    'dart_krx' => [
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '5 13 * * *',
        'get'  => ['key' => DART_KEY, 'job' => 'krx'],
        'desc' => 'KRX 최근 거래일 상장주식수 (T+1 이라 오후 1회 — 새벽 금지)',
    ],
    /* ★ 크론 사이트에 등록하지 않는다 — 거래대금 이관은 dart_krx(13:05) 안에서 이미 끝난다.
     *   여기 남겨 둔 것은 그 크론이 실패했을 때 손으로 한 번 돌리기 위한 예비 경로다. */
    'krx_amt' => [
        'file' => 'cron/krx_amt.php', 'bg' => false, 'cron' => '(등록 안 함 · 예비)',
        'get'  => ['key' => 'econ-krx-amt', 'job' => 'daily'],
        'desc' => '[예비] 거래대금 이관 — 평소엔 dart_krx 가 대신한다. 등록 불필요',
    ],
    'dart_eod' => [
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '50 15 * * *',
        'get'  => ['key' => DART_KEY, 'job' => 'eod'],
        'desc' => '마감 뒤 묶음 — 시세 메우기 + 오늘 고·저 + 일봉 이력',
    ],
    'etf_update' => [
        'file' => 'cron/keyword_collector.php', 'bg' => true, 'cron' => '20 16 * * *',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'etf_update'],
        'desc' => 'ETF 편입종목 갱신 (ETF당 sleep 2초 · bg 필수)',
    ],

    // ── 매월 도는 것 ────────────────────────────────────────────────────
    'naver_food' => [
        'file' => 'cron/naver_collect.php', 'bg' => true, 'cron' => '0,10,20,30,40,50 3-7 1 * *',
        'get'  => ['key' => NAVER_KEY, 'cat' => 'food', 'max' => '10', 'delay' => '4', 'max_sec' => '120'],
        'desc' => '네이버 맛집 전국 수집 (매월 1일 · 30회 fire 로 나눠 드레인)',
    ],
    'naver_stay' => [
        'file' => 'cron/naver_collect.php', 'bg' => true, 'cron' => '0,10,20,30,40,50 3-7 2 * *',
        'get'  => ['key' => NAVER_KEY, 'cat' => 'stay', 'max' => '10', 'delay' => '4', 'max_sec' => '120'],
        'desc' => '네이버 스테이 수집 (매월 2일 · 스테이+펜션 합집합)',
    ],
    'naver_camping' => [
        'file' => 'cron/naver_collect.php', 'bg' => true, 'cron' => '0,10,20,30,40,50 2-7 3 * *',
        'get'  => ['key' => NAVER_KEY, 'cat' => 'camping', 'max' => '8', 'delay' => '4', 'max_sec' => '180'],
        'desc' => '네이버 캠핑장 수집 (매월 3일 · 캠핑장+오토캠핑 합집합이라 max 낮춤)',
    ],
    'ardent' => [
        'file' => 'cron/ardent_crawl.php', 'bg' => true, 'cron' => '1 3 4 * *',
        'get'  => ['key' => 'econ-ardent', 'run_ai' => '1', 'budget' => '180'],
        'desc' => '아덴트뉴스 국내여행 → AI 추출 → 여행지 적재 (매월 4일)',
    ],

    // ── 연 1회 ──────────────────────────────────────────────────────────
    'holidays' => [
        'file' => 'cron/schedule_alert.php', 'bg' => false, 'cron' => '0 0 1 1 *',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'sync_holidays'],
        'desc' => '공휴일 동기화 (올해+내년)',
    ],

    // ── 크론 미등록: 확인용 (아무것도 안 건드린다) ───────────────────────
    'dart_status' => [
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'status'],
        'desc' => '[확인] DART·KRX 적재 현황',
    ],
    'dart_log' => [
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'log'],
        'desc' => '[확인] dart 의 지난 bg 실행 로그 (dart 만 log=1 이 아니라 job=log 다)',
    ],
    'dart_slots' => [
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'slots'],
        'desc' => '[확인] fresh 가 고를 슬롯 미리보기 (&today=2026-08-15 로 미래도)',
    ],

    // ── 크론 미등록: 수동·SSH 전용 (길거나 1회성) ────────────────────────
    'dart_daily' => [
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'daily'],
        'desc' => '[수동] 보유·관심종목 일봉 이력 (eod 가 증분을 포함 · &full=1 이면 통째)',
    ],
    'dart_range_full' => [
        'file' => 'cron/dart_collect.php', 'bg' => true, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'range', 'full' => '1'],
        'desc' => '[SSH] 일별 고·저 씨 뿌리기 — 전종목 2,766콜 · 340초 · 최초 1회',
    ],
    'dart_quarter' => [
        'file' => 'cron/dart_collect.php', 'bg' => true, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'quarter'],
        'desc' => '[SSH] 분기 재무제표 최초 적재 (~8분 · &from= &to= &budget=)',
    ],
    'dart_shares' => [
        'file' => 'cron/dart_collect.php', 'bg' => true, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'shares'],
        'desc' => '[SSH] 연도별 주식수 (종목당 1콜 · ~54분 · &budget= 로 조각내기)',
    ],
    'place_geo' => [
        'file' => 'cron/place_geocode.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => 'econ-place-geo'],
        'desc' => '[수동] place 좌표화 배치 (&limit= &reset_failed=1)',
    ],
    'waste_geo' => [
        'file' => 'cron/waste_geocode.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => 'econ-waste-geo'],
        'desc' => '[수동] waste_companies 좌표화 배치',
    ],
    'place_tag' => [
        'file' => 'cron/place_tag_backfill.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => 'econ-place-tag'],
        'desc' => '[수동] 장소 자동 태깅 (&dry=1 &season=1 &after=)',
    ],
    'lunar' => [
        'file' => 'cron/lunar_seed.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => 'econ-lunar-seed'],
        'desc' => '[1회] 음양력 변환표 1990~2050 적재 (스스로 이어실행)',
    ],
    'ardent_status' => [
        'file' => 'cron/ardent_crawl.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => 'econ-ardent', 'status' => '1'],
        'desc' => '[확인] 아덴트 크롤 진행 상태',
    ],
];

// ── 인증 ────────────────────────────────────────────────────────────────
// CLI 는 서버에 들어와야 돌릴 수 있으니 그 자체가 자격이다.
if (!$CLI && ($_GET['k'] ?? '') !== CRON_JOB_KEY) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("forbidden — ?k=<토큰> 이 필요합니다.\n");
}

$task = (string)($_GET['task'] ?? '');

// ── task=list : 등록된 잡 전체 ───────────────────────────────────────────
if ($task === '' || $task === 'list') {
    header('Content-Type: text/plain; charset=utf-8');
    $base = ($CLI ? '' : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'economist.kr')) . '/cron_job.php?task=';

    /* 정렬은 <b>ASCII 열만</b> sprintf 로 맞춘다. 한글은 sprintf 가 바이트로 세어
     * 폭이 어긋나므로 설명·인자는 둘째 줄에 자유 형식으로 둔다. */
    $line = str_repeat('=', 84);
    echo "등록된 크론 작업 " . count(TASKS) . "개\n";
    echo "호출: {$base}<task>&k=<토큰>\n";
    echo "      ※ task 를 앞에 둔다 — 크론 사이트 목록이 URL을 잘라도 이름이 보이게\n";
    echo $line . "\n";
    echo sprintf("%-17s %-2s %-28s %s\n", 'task', 'bg', 'cron', 'file');
    echo str_repeat('-', 84) . "\n";

    $section = null;
    foreach (TASKS as $name => $t) {
        // 크론 등록된 것 / 안 된 것 사이에 구분선
        $isCron = ($t['cron'] ?? '') !== '';
        if ($section !== null && $section !== $isCron) {
            echo str_repeat('-', 84) . "\n· 아래는 크론 미등록 — 수동/SSH 전용\n" . str_repeat('-', 84) . "\n";
        }
        $section = $isCron;

        echo sprintf("%-17s %-2s %-28s %s\n",
            $name,
            !empty($t['bg']) ? 'Y' : '-',
            $isCron ? $t['cron'] : '(manual)',
            $t['file']);

        // 넘기는 인자 — 토큰은 가린다
        $shown = [];
        foreach ($t['get'] as $k => $v) {
            $shown[] = $k . '=' . (in_array($k, ['ssk', 'key'], true) ? '***' : $v);
        }
        echo '     ' . ($shown ? implode(' ', $shown) . '  ·  ' : '') . ($t['desc'] ?? '') . "\n";
    }
    echo $line . "\n";
    echo "※ cron 열은 사람이 읽는 기록입니다 — 실제 스케줄의 진실은 cron-job.org 에 있습니다.\n";
    echo "※ 옵션·모드를 바꾸려면 크론 사이트가 아니라 이 파일(cron_job.php)의 TASKS 를 고칩니다.\n";
    exit;
}

// ── 라우팅 ──────────────────────────────────────────────────────────────
if (!isset(TASKS[$task])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("알 수 없는 task: {$task}\n등록된 목록은 ?k=…&task=list\n");
}

$t    = TASKS[$task];
$path = __DIR__ . '/' . $t['file'];
if (!is_file($path)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("task={$task} 의 파일이 없습니다: {$t['file']}\n배포가 누락됐는지 확인하세요.\n");
}

/* $_GET 을 갈아끼운다.
 *   · 레지스트리 값이 <b>이긴다</b> ($a + $b 는 왼쪽 우선) → 토큰·모드는 밖에서 못 덮는다
 *   · 나머지 인자는 그대로 통과 (log=1 · status=1 · sec= · full= 같은 운영·디버그용)
 *   · k 와 task 는 <b>반드시 남긴다</b> — bg 자기호출이 이 URL로 되돌아오기 때문 (상단 주석 참조)
 */
$explain = !empty($_GET['explain']);
unset($_GET['explain']);

$g = $t['get'] + $_GET;
$g['task'] = $task;
if (!$CLI) $g['k'] = CRON_JOB_KEY;
/* bg 는 "본작업"일 때만 붙인다.
 * log=1 · status=1 같은 <b>읽기 전용 조회까지 bg 로 돌리면 결과가 화면이 아니라 로그로 가서</b>
 * 아무것도 못 보게 된다(호출자는 "queued" 만 받는다). 조회는 즉시·동기로 돌린다. */
$readOnly = !empty($g['log']) || !empty($g['status']) || !empty($g['explain']);
if (!empty($t['bg']) && empty($g['run']) && !$readOnly) $g['bg'] = '1';
$_GET = $g;

/* ?explain=1 — <b>실행하지 않고</b> "무엇을 어떻게 부를지"만 보여 준다.
 * 레지스트리를 고친 뒤 의도대로 조립됐는지 확인하는 용도다(부작용 0). */
if ($explain) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "task    : {$task}\n";
    echo "desc    : " . ($t['desc'] ?? '') . "\n";
    echo "file    : {$t['file']}\n";
    echo "cron    : " . (($t['cron'] ?? '') !== '' ? $t['cron'] : '(크론 미등록 — 수동)') . "\n";
    echo "bg      : " . (!empty($t['bg']) ? 'Y (30초 우회 · 자기호출)' : '-') . "\n";
    echo str_repeat('-', 60) . "\n";
    echo "타깃이 받을 \$_GET:\n";
    foreach ($_GET as $k => $v) {
        echo sprintf("  %-10s = %s\n", $k, in_array($k, ['ssk', 'key', 'k'], true) ? '***' : $v);
    }
    echo str_repeat('-', 60) . "\n";
    if ($CLI) {
        echo "CLI 실행 — bg·자기호출은 웹에서만 동작한다(그대로 앞에서 끝까지 돈다).\n";
    } elseif (!empty($_GET['bg'])) {
        // cronbg 가 만들 자기호출 URL (되돌아오는 요청) — k·task 가 살아 있어야 정상
        $q = $_GET; unset($q['bg']); $q['run'] = '1';
        foreach (['ssk', 'key', 'k'] as $s) if (isset($q[$s])) $q[$s] = '***';
        echo "bg 자기호출 URL (되돌아오는 요청):\n  "
           . (string)($_SERVER['SCRIPT_NAME'] ?? '/cron_job.php') . '?' . urldecode(http_build_query($q)) . "\n";
        echo "  ※ k 와 task 가 둘 다 보여야 정상이다 — 하나라도 빠지면 되돌아온 요청이\n"
           . "     토큰 검사에 막히거나 라우팅되지 못해 bg 작업이 조용히 사라진다.\n";
    } else {
        echo "bg 없이 앞에서 끝까지 돈다 (30초 안에 끝나는 잡이거나 읽기 전용 조회).\n";
    }
    exit;
}

require $path;
?>
