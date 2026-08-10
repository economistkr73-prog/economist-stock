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

/* CLI 는 `task=etf_update` 꼴 인자를 $_GET 처럼 받는다.
 * ★CRON_REGISTRY_ONLY(레지스트리만 읽기 · 아래 가드 참조)일 때는 건너뛴다 —
 *   그 경로는 남의 스크립트 안에서 도는 것이라 $_GET 을 건드리면 안 되고,
 *   CLI 라도 $argv 가 없는 실행 환경이 있다(register_argc_argv=Off). */
if ($CLI && !defined('CRON_REGISTRY_ONLY')) {
    foreach (array_slice($argv ?? [], 1) as $a) {
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
        'file' => 'cron/dart_collect.php', 'bg' => false, 'cron' => '50 15 * * 1-5',
        'get'  => ['key' => DART_KEY, 'job' => 'eod'],
        'desc' => '마감 뒤 묶음 — 시세 메우기 + 오늘 고·저 + 일봉 이력',
    ],
    'etf_update' => [
        'file' => 'cron/keyword_collector.php', 'bg' => true, 'cron' => '20 16 * * 1-5',
        'get'  => ['ssk' => KWC_KEY, 'mode' => 'etf_update'],
        'desc' => 'ETF 편입종목 갱신 (ETF당 sleep 2초 · bg 필수)',
    ],
    /* ★ 마감 뒤인 이유 — 이 잡은 「오늘이 거래일인가」를 krx_amt 의 오늘 행으로 판정하는데,
     *   그 행은 15:50 dart_eod 가 넣는다. 그 앞에 두면 매일 「휴장일」로 오판해 조용히 건너뛴다.
     *   (판정에 all_stock_info.uDate 폴백이 있지만, 순서를 지키는 편이 낫다)
     * ★16:45 인 이유 — 바로 위 etf_update(16:20)가 실측 901.7초(435 ETF × sleep 2초)라 16:35 까지 돈다.
     *   16:25 로 두면 그 한복판에서 시작해 10분을 겹친다. 늦춰도 잃는 것이 없어 뒤로 물렸다. */
    'dt_min' => [
        'file' => 'cron/dt_min.php', 'bg' => true, 'cron' => '45 16 * * 1-5',
        'get'  => ['key' => 'econ-dt-min', 'job' => 'daily'],
        'desc' => '단타 분봉 — 당일 수집 → 만료 삭제 → 구멍 치유 → 분할 감지 → 적재율 감시 (종목당 1초 · bg 필수)',
    ],

    // ── 급등주 분봉 아카이브 (한시적 적재 · 끝나면 크론에서 내린다) ───────
    /* ★`job` 을 레지스트리에 넣지 않는다 — 그래야 &job=probe/events/verify/feat 를
     *   URL 로 골라 부를 수 있다(레지스트리 값은 사용자 인자보다 «우선»한다).
     * ★장중에는 돌리지 않는다. 한 콜 1초 × 수천 콜이라 밤에 천천히 채우는 잡이다.
     *   수집이 끝나면(qm_task 에 state IN (0,1) 이 0건) 크론 사이트에서 내린다. */
    /* ★적재 중에만 등록한다 — 끝나면(qm_task 에 state IN (0,1) 이 0건) 크론 사이트에서 내린다.
     *   URL: cron_job.php?task=qm&k=…&job=work
     *   워커는 `GET_LOCK('qm_work')` 로 «한 번에 하나»만 도므로, 서버에서 백그라운드로 돌고 있어도
     *   크론이 겹쳐 부를 걱정이 없다(살아 있으면 그냥 물러난다). 죽어 있으면 크론이 이어받는다. */
    'qm' => [
        'file' => 'cron/qm_collect.php', 'bg' => true, 'cron' => '*/15 * * * *  (적재 중에만)',
        'get'  => ['key' => 'econ-qm'],
        'desc' => '급등주 분봉 아카이브 — &job=work 로 적재 (다중 실행은 DB 락이 막는다)',
    ],

    /* ★16:20 인 이유 — 오늘 행은 15:50 dart_eod 가 넣는다(잠정 src='n').
     *   그 앞에 두면 매일 「그 날 봉이 없다」로 조용히 0건이 된다.
     *   ETF 갱신(16:20 · 15분)과 겹치지만 이 잡은 API 콜 0회에 실측 수 초라 부딪히지 않는다.
     * ★잡이 «직전 거래일»도 함께 다시 잰다 — 13:05 KRX 확정값이 잠정치를 덮으면
     *   점수가 바뀌기 때문이다(bx_scan.php 의 daily 참고). */
    /* ★알림 등급 하한은 «여기»가 정한다 — A(상위5%·하루 1.2건) / B(상위10%·2.2건) / C(상위25%·4.7건).
     *   시끄러우면 ag 를 A 로, 더 보려면 C 로 고친다. 크론 사이트는 손대지 않는다.
     *   조용히 적재만 하려면 alert=0.
     * ★mincalls=400 인 이유 — 2026-08-09 에 점수 컷을 없애 하루 12.4건이 되었다. 새 것만
     *   12.4 × ~8콜 ≈ 100콜이라 기본값 150 이면 <b>밀린 것(3,026건)을 영영 못 채운다</b>.
     *   400 이면 새 것 100 + 밀린 것 300(≈37건/일)이라 두 달쯤에 따라잡는다. 1 req/s 라 최대 400초.
     * ★bg=false 인 이유 — 적재·판정은 API 0회라 수 초로 끝나고, 분봉만 키움 콜(1 req/s)이라
     *   예산 150 이면 최대 150초다. 30초 벽은 «응답»에 걸리는 것이라 CLI/크론엔 무관하다. */
    'boxbrk' => [
        'file' => 'cron/bx_scan.php', 'bg' => false, 'cron' => '20 16 * * 1-5',
        'get'  => ['key' => 'econ-bx', 'job' => 'daily', 'ag' => 'B', 'mincalls' => '400'],
        'desc' => '박스 상향돌파 — 적재 → 결과 판정 → 1분봉 수집 → 새 후보 알림 (패턴분석 화면의 원장)',
    ],

    // ── 매월 도는 것 ────────────────────────────────────────────────────
    'naver_food' => [
        'file' => 'cron/naver_collect.php', 'bg' => true, 'cron' => '0,10,20,30,40,50 3-7 1 * *',
        'get'  => ['key' => NAVER_KEY, 'cat' => 'food', 'max' => '10', 'delay' => '4', 'max_sec' => '120'],
        'desc' => '네이버 맛집 전국 수집 (매월 1일 · 30회 fire 로 나눠 드레인)',
    ],
    'naver_stay' => [
        'file' => 'cron/naver_collect.php', 'bg' => true, 'cron' => '1,12,22,30,39,50 3-7 2 * *',
        'get'  => ['key' => NAVER_KEY, 'cat' => 'stay', 'max' => '10', 'delay' => '4', 'max_sec' => '120'],
        'desc' => '네이버 스테이 수집 (매월 2일 · 스테이+펜션 합집합)',
    ],
    'naver_camping' => [
        'file' => 'cron/naver_collect.php', 'bg' => true, 'cron' => '0,10,20,30,40,50 2-7 3 * *',
        'get'  => ['key' => NAVER_KEY, 'cat' => 'camping', 'max' => '8', 'delay' => '4', 'max_sec' => '180'],
        'desc' => '네이버 캠핑장 수집 (매월 3일 · 캠핑장+오토캠핑 합집합이라 max 낮춤)',
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
    /* 매일 몫은 dart_fresh 안에 들어 있다 — 이건 옛 연도를 한 번에 메우는 일회성 배치다 */
    'dart_nifix' => [
        'file' => 'cron/dart_collect.php', 'bg' => true, 'cron' => '',
        'get'  => ['key' => DART_KEY, 'job' => 'nifix'],
        'desc' => '[SSH] 순이익 결측 보수 (행당 1콜 · &budget= &y= &rc=)',
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
    'ardent_new' => [
        'file' => 'cron/ardent_crawl.php', 'bg' => false, 'cron' => '',
        'get'  => ['key' => 'econ-ardent', 'new_list' => '1'],
        'desc' => '[확인] 아직 안 본 아덴트 기사 목록 (Claude 콜 0회 · &fmt=json &pages= &limit=)',
    ],
    /* 2026-08-04 크론 등록 해제(사용자 지시). 여행지 DB 가 이미 3만 곳이라 상시 수집을 멈췄다.
     * ★비용 주의: 기사 1건 = Claude 콜 1회(≈$0.015). 2026-08-04 실측으로 491건 처리에 $6 을 써
     *   ANTHROPIC_API_KEY 잔액을 비웠고, 그 키를 같이 쓰는 모닝브리핑·명함스캔·음성일정등록이 함께 멈췄다.
     *   지금 이 잡에는 콜 상한이 없다(있는 것은 1회 실행 시간예산 180초뿐) → 손으로 돌릴 때는
     *   &budget= 을 작게 줘서 조금씩 돌린다. 다시 크론에 올릴 거면 콜 상한부터 넣는다. */
    'ardent' => [
        'file' => 'cron/ardent_crawl.php', 'bg' => true, 'cron' => '',
        'get'  => ['key' => 'econ-ardent', 'run_ai' => '1', 'budget' => '180'],
        'desc' => '[수동] 아덴트뉴스 국내여행 → AI 추출 → 여행지 적재 (⚠유료 API·상한 없음 · 2026-08-04 크론 해제)',
    ],
];

/* ── 레지스트리만 읽고 싶을 때 (2026-08-06) ──────────────────────────────
 * 「설정 > 시세 설정」 화면이 크론 시각·설명을 <b>여기서</b> 가져간다.
 * 시각표를 화면에 손으로 옮겨 적으면 크론을 옮길 때 그 화면이 조용히 거짓말을 시작한다 —
 * 단일본은 위의 TASKS 하나여야 한다(CRON.md §1 표가 이 파일을 보는 것과 같은 이유).
 *
 * 여기서 되돌아가므로 <b>인증도 디스패치도 일어나지 않는다</b>(부작용 0).
 * 부르는 쪽: stock/lib/quote.php 의 quote_cron_tasks().
 * ★소비자는 CRON_JOB_KEY 를 화면에 내보내지 않는다 — 이 include 로 그 상수도 정의된다. */
if (defined('CRON_REGISTRY_ONLY')) return;

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

/* ── 중앙 실패 알림 (2026-08-02) ─────────────────────────────────────────
 * 여기서 감싸면 <b>모든 task 가 한 번에</b> 커버된다 — 개별 크론에 실패 알림을
 * 흩뿌릴 필요가 없다. bg 잡은 0.07초 만에 queued 를 반환해 cron-job.org 눈에는
 * 항상 성공이므로, 뒤(run 모드)에서 죽는 실패는 <b>여기가 유일한 감지 지점</b>이다
 * (되돌아온 run 요청도 이 디스패처를 거친다).
 *
 *   · 잡히지 않은 예외 → try/catch
 *   · 치명 오류(파스·메모리 등) → register_shutdown_function + error_get_last()
 *   · 같은 task 는 하루 1회만 알림 (연속 실패 폭탄 방지 · /tmp 플래그)
 *   · priority 1 = 방해금지 무시 — "실패는 크게, 성공은 조용히"
 * 읽기 전용 조회(log·status)는 화면에서 바로 보이므로 안 감싼다. */
function cron_fail_notify(string $task, string $reason): void
{
    $flag = sys_get_temp_dir() . '/cron_fail_' . preg_replace('/[^a-z0-9_]/i', '', $task) . '.flag';
    if (is_file($flag) && trim((string)@file_get_contents($flag)) === date('Y-m-d')) return;
    @file_put_contents($flag, date('Y-m-d'));
    /* 타깃이 cnt.inc(오토로더)를 로드하기 전에 죽었을 수 있다 — 직접 로드 폴백 */
    if (!class_exists('Notify', false)) {
        foreach (['/classes/PushoverNotify.class', '/classes/Notify.class'] as $c) {
            if (is_file(__DIR__ . $c)) require_once __DIR__ . $c;
        }
    }
    if (!class_exists('Notify')) { error_log("[cron_job] 실패(알림 불가) task={$task}: {$reason}"); return; }
    // mbstring 미설치 환경(로컬 CLI 테스트 등)에서도 알림 자체는 나가게 폴백
    $reason = function_exists('mb_strcut') ? mb_strcut($reason, 0, 500) : substr($reason, 0, 500);
    try {
        Notify::send(
            "task={$task}\n" . $reason
            . "\n\n로그: https://economist.kr/cron_job.php?task={$task}&k=" . CRON_JOB_KEY . "&log=1",
            "https://economist.kr/cron_job.php?task=list&k=" . CRON_JOB_KEY,
            ['title' => "⚠️ 크론 실패: {$task}", 'priority' => 1]
        );
    } catch (Throwable $e) {
        error_log('[cron_job] 실패 알림 발송 불가: ' . $e->getMessage());
    }
}

if (!$readOnly) {
    register_shutdown_function(function () use ($task) {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            cron_fail_notify($task, "치명 오류: {$e['message']} @ " . basename($e['file']) . ":{$e['line']}");
        }
    });
    try {
        require $path;
    } catch (Throwable $e) {
        cron_fail_notify($task, get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        throw $e;   // 원래 동작(500 + 서버 에러로그)은 그대로 둔다
    }
} else {
    require $path;
}
?>
