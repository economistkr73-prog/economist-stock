<?php
/**
 * stock/lib/quote.php — 「설정 > 시세 설정」의 원본 (카탈로그 + 실측 탐침)
 *
 * ══ 왜 이 파일이 있나 ═════════════════════════════════════════════════════
 * 이 사이트의 시세는 <b>한 곳에서 오지 않는다</b>. 화면 다섯이 각자 다른 표를 읽고,
 * 그 표를 채우는 크론이 일곱이며, 어떤 값은 DB 를 아예 거치지 않고 화면이 그 자리에서 받는다.
 * 「지금 이 숫자가 언제 것인가」를 알려면 그 사슬을 따라가야 하는데, 사슬이 코드에 흩어져 있었다.
 *
 * 그래서 <b>사슬 자체를 데이터로</b> 적어 둔다. 화면은 이 카탈로그를 그리기만 한다
 * (`Thr.class`·`ChartFeat.class` 와 같은 「원본 → 화면 표시」 패턴).
 *
 * ★ 여기에 <b>크론 시각을 손으로 적지 않는다</b> — `cron_job.php` 의 `TASKS` 를 읽어 온다.
 *   손으로 적으면 크론을 옮길 때 이 화면이 조용히 거짓말을 시작한다(CRON.md 가 겪은 그 일이다).
 *   task 이름만 적고 시각·설명·bg 는 레지스트리에서 가져온다.
 *
 * ★ 임계·주기 상수도 다시 적지 않는다 — `NaverFinanceAPI::QUOTE_MAX_AGE`·`Dt::WINDOW_DAYS` 같은
 *   값을 그대로 보간한다. 상수를 고치면 이 화면의 글자가 함께 바뀌어야 한다.
 *
 * 구성
 *   quote_sources()      원천 표 카탈로그 (무엇을 담나 · 누가 채우나 · 얼마나 보관하나 · 함정)
 *   quote_screens()      화면별 시세 지도 (다섯 화면이 각 항목을 어디서 읽나)
 *   quote_externals()    외부 API 총람 (한도 · 끊기면 무엇이 멈추나)
 *   quote_cron_tasks()   cron_job.php 의 TASKS 를 <b>부작용 없이</b> 읽어 온다
 *   quote_probe_all()    표별 실측 (최신 시각 · 행 수 · 용량) — 카탈로그가 아니라 「지금」
 */

require_once __DIR__ . '/fmt.php';   // pf_h()

// ══════════════════════════════════════════════════════════════════════════
//  1. 크론 레지스트리 읽기 — 단일본은 cron_job.php
// ══════════════════════════════════════════════════════════════════════════
/**
 * `cron_job.php` 의 `TASKS` 를 실행 없이 읽어 온다.
 *
 * ★ 그 파일은 크론의 유일한 진입점이라 include 하면 인증하고 디스패치한다.
 *   `CRON_REGISTRY_ONLY` 를 정의해 두면 상수만 정의한 채 되돌아온다(그 파일의 가드 참조).
 *   토큰(`CRON_JOB_KEY`)도 함께 정의되지만 <b>이 함수는 그것을 내보내지 않는다</b>.
 *
 * @return array<string,array> task 이름 → ['cron'=>crontab, 'desc'=>설명, 'bg'=>bool, 'file'=>경로]
 */
function quote_cron_tasks(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $f = $_SERVER['DOCUMENT_ROOT'] . '/cron_job.php';
    if (!is_file($f)) return $cache = [];

    if (!defined('CRON_REGISTRY_ONLY')) define('CRON_REGISTRY_ONLY', 1);
    require_once $f;

    $cache = defined('TASKS') ? (array)constant('TASKS') : [];
    return $cache;
}

/** 한 task 의 등록 정보. 없으면 빈 배열 */
function quote_cron(string $task): array
{
    $t = quote_cron_tasks();
    return $t[$task] ?? [];
}

/**
 * 다른 파일의 `const NAME = 숫자;` 를 <b>실행 없이</b> 읽어 온다.
 *
 * ★왜 필요한가 — 상수를 화면에 손으로 옮겨 적으면 그 값을 고치는 날 화면이 거짓말을 시작한다.
 *   실제로 이 화면이 <code>QM_MAX_MB</code> 를 「300」이라 적었다가 잡혔다(사양서 초안의 값이었고
 *   코드는 이미 1600 이었다 — 표가 1.32GB 라 「가드를 4배 넘겼다」로 보였다).
 *   그런데 그 상수들이 사는 파일(<code>cron/qm_collect.php</code>)은 include 하면 <b>작업이 돈다</b> —
 *   <code>cron_job.php</code> 처럼 되돌아갈 가드도 없다. 그래서 소스를 글자로 읽는다.
 *
 * @return int|float|null 못 찾으면 null (호출자가 그 칸을 생략한다)
 */
function quote_const_from_file(string $relPath, string $name)
{
    static $cache = [];
    $k = $relPath . '#' . $name;
    if (array_key_exists($k, $cache)) return $cache[$k];

    $cache[$k] = null;
    $f = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relPath, '/');
    if (!is_file($f)) return null;
    $src = (string)@file_get_contents($f);
    if ($src === '') return null;
    /* ★<code>const</code> 뒤의 <b>공백을 빼먹으면 안 된다</b> — 실측에서 그 한 글자 때문에
     *   <code>const QM_MAX_MB    = 1600;</code> 을 못 찾아 조용히 null 이 됐다. */
    if (preg_match('/^\s*(?:const\s+|define\(\s*[\'"])' . preg_quote($name, '/')
                 . '[\'"]?\s*(?:=|,)\s*([0-9]+(?:\.[0-9]+)?)/m', $src, $m)) {
        $cache[$k] = (strpos($m[1], '.') !== false) ? (float)$m[1] : (int)$m[1];
    }
    return $cache[$k];
}

/**
 * crontab 식에서 <b>첫 실행 시각</b>(분 단위)을 뽑는다 — 하루 시간표를 정렬하려고.
 *
 * ★주석에 crontab 식을 그대로 적지 않는다 — 스텝 표기(별표+슬래시)가 블록 주석을 그 자리에서 닫는다.
 *   예: 장중 10분 주기는 9:00, `5 6-9,11,13 * * 1-5` 는 6:05, `50 15 * * 1-5` 는 15:50 이 된다.
 * 파싱에 실패하면 1440(맨 뒤)을 준다 — 순서만 정하는 값이라 틀려도 화면이 깨지지 않는다.
 */
function quote_cron_minute(string $cron): int
{
    $p = preg_split('/\s+/', trim($cron));
    if (!$p || count($p) < 5) return 1440;
    $first = static function (string $f): ?int {
        $f = explode(',', $f)[0];              // 5,15 → 5
        $f = explode('-', $f)[0];              // 6-9  → 6
        if (strpos($f, '/') !== false) {       // */10 → 0
            $b = explode('/', $f)[0];
            $f = ($b === '*') ? '0' : $b;
        }
        if ($f === '*') return 0;
        return is_numeric($f) ? (int)$f : null;
    };
    $m = $first($p[0]);
    $h = $first($p[1]);
    if ($m === null || $h === null) return 1440;
    return $h * 60 + $m;
}

/**
 * crontab 식을 사람 말 한 줄로 — 「평일 15:50~」 / 「매일 15분마다」.
 *
 * ★분 필드에 스텝(별표+슬래시)이 있으면 <b>시각으로 적지 않는다</b> — 15분마다 도는 잡을
 *   「00:00」이라고 쓰면 하루 시간표의 맨 앞에 앉아 「하루가 이것으로 시작한다」로 읽힌다.
 */
function quote_cron_when(string $cron): string
{
    if ($cron === '') return '수동';
    $days = quote_cron_days($cron);
    $p    = preg_split('/\s+/', trim($cron));
    $min  = $p[0] ?? '*';
    if (strpos($min, '/') !== false) {
        $step = explode('/', $min)[1];
        $hour = ($p[1] ?? '*');
        $scope = ($hour === '*') ? '' : ' (' . $hour . '시)';
        return $days . ' ' . $step . '분마다' . $scope;
    }
    $m = quote_cron_minute($cron);
    if ($m >= 1440) return $days;
    return $days . ' ' . sprintf('%02d:%02d~', intdiv($m, 60), $m % 60);
}

/** crontab 식을 사람 말로 — 요일 필드만 읽는다(시각은 crontab 원문을 그대로 보여 준다) */
function quote_cron_days(string $cron): string
{
    $p = preg_split('/\s+/', trim($cron));
    $dow = $p[4] ?? '*';
    if ($dow === '1-5') return '평일';
    if ($dow === '1-6') return '월~토';
    if ($dow === '*')   return '매일';
    return $dow;
}

// ══════════════════════════════════════════════════════════════════════════
//  2. 원천 표 카탈로그
// ══════════════════════════════════════════════════════════════════════════
/**
 * 시세가 사는 표들.
 *
 *   label  표시 이름
 *   what   무엇을 담나 (한 행이 무엇인가)
 *   grain  낟알 — 스냅샷 / 일봉 / 1분봉
 *   src    바깥 어디서 오나
 *   tasks  이 표를 <b>쓰는</b> task 이름 (시각·설명은 cron_job.php 에서 가져온다)
 *   ui     화면이 직접 채우기도 하면 그 설명 (크론만으로는 설명이 안 되는 표가 있다)
 *   keep   보관 정책
 *   probe  실측 함수 키 (quote_probe_all)
 *   warn   ★함정 — 이 표를 새로 읽는 사람이 반드시 알아야 하는 것
 */
function quote_sources(): array
{
    return [

        'all_stock_info' => [
            'label' => 'all_stock_info',
            'title' => '전종목 현재가 스냅샷',
            'what'  => '종목당 1행 — 현재가·전일대비·등락률·거래량·거래대금(억)·시가총액(억). <b>이력이 없다</b>',
            'grain' => '스냅샷 (지금 시점)',
            'src'   => '네이버 금융 — 전종목 목록 / 폴링 API(100종목 1콜). '
                     . '<b>NXT 장외는 쓰지 않는다</b>(<code>NaverFinanceAPI::USE_NXT=false</code>)',
            'tasks' => ['stock_news', 'dart_eod'],
            'ui'    => '<b>단타</b>가 장중 ' . Dt::TICK_SEC . '초마다 <b>수집 대상 종목만</b> 실시간으로 받는다 '
                     . '(<code>Dt::refreshQuotesLive()</code> → 키움 <code>ka10095</code> · '
                     . '100종목까지 한 요청 · 실패하면 네이버 폴백). '
                     . '<b>포트폴리오 계열</b>(현황·포트폴리오 상세·보유종목·종목상세·매매히스토리 — '
                     . '<code>pf_load_calc()</code>)과 <b>관심종목</b>은 화면에 들어올 때 보유 종목만 '
                     . '네이버로 메운다(<code>Pf::refreshQuotes()</code> · 신선도 '
                     . NaverFinanceAPI::QUOTE_MAX_AGE . '초)',
            'keep'  => '스냅샷이라 보관 개념이 없다 — 덮어쓴다',
            'probe' => 'all_stock_info',
            'warn'  => [
                '★<b>사이트 전 주가의 단일 원천</b>이다. 네이버가 막히면 다섯 화면이 함께 멈춘다.',
                '★정규 경로에 <b>DELETE … NOT IN</b> 이 있다 — 네이버가 부분 응답을 주면 종목이 통째로 사라진다.',
                '★정규 수집은 <b>평일만</b>이다. 주말에 열면 금요일 값이다.',
                '★★<b>NXT(시간외)를 쓰지 않는다</b>(2026-08-06 · <code>USE_NXT=false</code>). '
                . '이 표는 사이트 전 화면이 읽는 단일 원천이라, 여기에 시간외 값이 들어오면 마감 뒤 '
                . '<b>목록과 차트가 다른 말을 한다</b>(실측 목록 231,500 vs 분봉·일봉 230,500). '
                . '기준을 <b>정규장 하나</b>로 뒀다.',
                '⇒ 그래서 <code>stock_news</code> 의 <b>08:05·17:05·19:05</b> fire 는 시세 갱신을 '
                . '<b>건너뛴다</b>(그 셋이 NXT 창이다 — 08:00~08:49 · 15:31~20:00). '
                . '그 회차의 나머지 일(급등 TOP 9 · 종목 뉴스 · 키워드)은 그대로 돈다. '
                . '★<b>정규 경로로 돌리지 않고 건너뛴다</b> — 장 밖에 전종목을 다시 받아 봐야 값은 그대론데 '
                . '그 경로엔 <code>DELETE … NOT IN</code> 이 있다.',
                '⇒ 마감 뒤 종가는 <b><code>dart_eod</code>(15:50)</b> 가 넣는 값으로 굳는다 — '
                . '그 크론을 지우거나 앞으로 당기면 종가가 통째로 틀어진다.',
                '★★<b>크론의 장중 정규 fire 는 09·11·13·15시 넷뿐이다</b> — 그래서 크론에만 기대는 종목은 '
                . '장중 시세가 최대 <b>약 2시간</b> 낡는다(14:50 에 열면 13:05 값). 화면이 자기 타이머로 '
                . '새로고침해도 <b>DB 를 다시 읽을 뿐</b>이라 같은 값이 온다. '
                . '단타에서 「차트와 옆 목록의 현재가가 다른 말을 한다」로 드러났던 문제이고, '
                . '2026-08-06 에 <b>수집 대상 종목만 키움으로 ' . Dt::TICK_SEC . '초마다 받아</b> 메웠다. '
                . '★<b>나머지 2,700여 종목은 여전히 크론 주기</b>다 — 탐색 화면(상위 종목·퀀트·스크리너)의 '
                . '현재가를 초 단위로 믿지 않는다.',
                '★<b>전종목을 화면 타이머로 갱신하지 않는다</b> — 28콜 × ' . Dt::TICK_SEC . '초면 하루 1만 콜이라 '
                . '「사이트 전 주가의 단일 원천」을 IP 차단 위험에 올린다. '
                . '「보는 종목만 촘촘히 · 전종목은 성기게」로 나눈 이유가 그것이다.',
                '★<code>uDate</code> 는 「값이 바뀐 시각」이 아니라 <b>「마지막으로 확인한 시각」</b>이다. '
                . '갱신 판정이 그 값을 보므로 명시적으로 찍는다(안 찍으면 값이 그대로인 거래정지 종목이 영원히 「낡음」이 된다).',
                '★<b>마감 뒤 갱신 판정은 「오늘 15:30 이전」</b>이다(<code>staleCutoff()</code>). '
                . 'NXT 를 끈 지금도 이 규칙은 남긴다 — 마감 뒤 같은 값을 되풀이해 받지 않게 막는 몫이 있고, '
                . 'NXT 를 되살릴 때 다시 필요해진다. ★그래서 <b>15:30~15:35 에 <code>uDate</code> 를 찍으면 안 된다</b> '
                . '— 그 조건 밖이라 영원히 신선으로 판정돼 <code>dart_eod</code> 의 종가 메우기가 건너뛴다(실측으로 겪었다).',
            ],
        ],

        'all_etf_price' => [
            'label' => 'all_etf_price',
            'title' => 'ETF 현재가 스냅샷',
            'what'  => 'ETF 당 1행 — 현재가·등락률·거래대금·시가총액·회전율',
            'grain' => '스냅샷 (지금 시점)',
            'src'   => '네이버 금융 ETF 목록',
            'tasks' => ['stock_news'],
            'keep'  => '스냅샷 — 덮어쓴다',
            'probe' => 'all_etf_price',
            'warn'  => [
                '★★<b>구멍 메우기가 없다.</b> <code>dart_eod</code>(15:50)가 부르는 '
                . '<code>refreshQuotes()</code> 도, NXT 경로도 <b>all_stock_info 만</b> 손댄다. '
                . 'ETF 를 갱신하는 것은 <code>stock_news</code> 의 <b>정규 경로 fire</b>뿐이다 '
                . '(06·07·09·11·13·15시 — 08·17·19시는 NXT 창이라 ETF 를 안 받는다).',
                '⇒ 그래서 ETF 시세는 <b>장중 최대 2시간</b> 낡고, 마감 뒤에는 <b>15:05 값</b>에 멈춘다 '
                . '(15:30 종가가 아니다). ETF 등락률로 판단할 때 이 사실을 감안한다.',
            ],
        ],

        'all_etf_holdings_info' => [
            'label' => 'all_etf_holdings_info',
            'title' => 'ETF 편입종목·비중',
            'what'  => '(ETF, 종목) 당 1행 — 편입비중·편입순위. <code>all_etf_info</code> 가 ETF 마스터',
            'grain' => '하루 1회 스냅샷',
            'src'   => '네이버 금융 ETF 상세 (ETF 당 1콜)',
            'tasks' => ['etf_update'],
            'keep'  => 'ETF 당 통째로 갈아 끼운다',
            'probe' => 'all_etf_holdings_info',
            'warn'  => [
                '★ETF 하나당 <code>sleep(2)</code> 라 435개 = <b>실측 901.7초</b>다. bg 가 필수이고, '
                . '「오래 안 받은 것부터」 정렬이라 예산에 걸려 끊겨도 다음 실행이 이어받는다.',
                '★단타·주식ETF분석 목록의 「ETF 편입 수」 배지가 이 표를 센다.',
            ],
        ],

        'data_update_status' => [
            'label' => 'data_update_status',
            'title' => '갱신 시각 기록',
            'what'  => '<code>data_key</code> 당 1행 — 그 수집이 <b>마지막으로 끝난 시각</b>. '
                     . '주식ETF분석 화면 상단의 「마지막 갱신」 글자가 여기서 온다',
            'grain' => '키별 타임스탬프',
            'src'   => '수집 코드가 끝나면서 스스로 찍는다 (<code>data_upTime()</code> · <code>env/e.fnc</code>)',
            'tasks' => ['stock_news', 'etf_update'],
            'keep'  => '키당 1행을 덮어쓴다',
            'probe' => 'data_update_status',
            'warn'  => [
                '★★<b>ETF 두 표의 갱신 «시각»은 여기서만 알 수 있다.</b> '
                . '<code>all_etf_price.uDate</code>·<code>all_etf_holdings_info.uDate</code> 는 '
                . '<b><code>DATE</code> 타입</b>이라 시각이 없다(실측 2026-08-06). '
                . '<code>all_stock_info.uDate</code>(<code>DATETIME</code>)와 다르므로, ETF 신선도를 '
                . '「몇 시간 전」으로 재려다 <b>밤새 안 받은 것처럼</b> 읽는 일이 생긴다.',
                '★<b>죽은 키가 섞여 있다</b> — <code>all_stock_info</code>·<code>all_etf_price</code> 키는 '
                . '2026-05 이후 아무도 안 찍는다. 살아 있는 것은 <code>all_data_from_naver</code>·'
                . '<code>all_data_from_nxt</code>·<code>auto_etf_naver_update</code> 셋뿐이다 — '
                . '새 화면이 신선도를 재려고 이 표를 읽는다면 <b>반드시 살아 있는 키인지 먼저 확인</b>한다.',
                '★<code>all_data_from_naver</code> 는 <b>정규 경로와 NXT 경로가 함께 찍는다</b>. '
                . '그래서 이 값만 보면 「전종목을 받았는지 604종목만 받았는지」를 못 가른다 — '
                . '<code>all_data_from_nxt</code> 와 나란히 봐야 한다(둘이 같은 시각이면 NXT 회차였다).',
            ],
        ],

        'krx_daily' => [
            'label' => 'krx_daily',
            'title' => 'KRX 최근 거래일 원본',
            'what'  => '전종목 — <b>상장주식수</b>·시세·시장구분. 최근 10거래일만 남긴다',
            'grain' => '일별',
            'src'   => 'KRX 오픈API (시장 2개 = 2콜)',
            'tasks' => ['dart_krx'],
            'keep'  => '최근 10거래일 (그 뒤는 prune)',
            'probe' => 'krx_daily',
            'warn'  => [
                '★<b>PER·PBR 의 분모</b>가 여기서 온다. 상장주식수는 1거래일에 11종목·1개월 203종목이 '
                . '바뀌므로(액면병합·감자·무상증자) <b>매일 받아야 한다</b> — 1개월 묵히면 PER 이 10배 틀어진다.',
                '★<b>새벽에 받으면 안 된다.</b> KRX 는 T+1 인데다 다음 날 오전에야 올라온다 '
                . '(실측 01:19 엔 없고 11:58 엔 있다). 그래서 13:05 오후 1회다.',
                '★재무 스크리너의 「거래종목 판정」도 이 표가 한다 — 네이버 목록엔 누락이 있어 쓰지 않는다.',
            ],
        ],

        'krx_amt' => [
            'label' => 'krx_amt',
            'title' => '전종목 일별 원장 (퀀트의 뿌리)',
            'what'  => '(종목, 날짜) 당 1행 — <b>실제 거래대금(원)</b>·거래량·시·고·저·종가·시총·상장주식수',
            'grain' => '일별 · 장기보관',
            'src'   => '확정(src=k)은 <code>krx_daily</code> 에서 이관(API 0회) · 당일 잠정(src=n)은 '
                     . '<code>all_stock_info</code> 스냅샷',
            'tasks' => ['dart_krx', 'dart_eod'],
            'keep'  => '지우지 않는다 (2019~ 백필 · 아카이브)',
            'probe' => 'krx_amt',
            'warn'  => [
                '★<b>단위는 원</b>이다 — 100억 = <code>10000000000</code>. '
                . '<code>all_stock_info.stock_vol_cap</code>(억원)과 다르다.',
                '★★<b>「거래일」의 정의가 이 표다</b> — 행이 있는 날이 곧 거래일이다. '
                . '휴장일 표를 따로 만들지 않는다(퀀트·포트폴리오·단타가 전부 이 규칙으로 산다).',
                '★그 <b>오늘 행을 넣는 것이 15:50 <code>dart_eod</code></b> 다. '
                . '단타 수집 크론이 16:45 인 이유가 그것이다 — 앞으로 당기면 매일 「휴장일」로 오판한다.',
                '★당일 값은 <b>네이버 잠정(src=n)</b> 이고, 다음 날 13:05 에 KRX 확정값(src=k)으로 덮인다.',
            ],
        ],

        'krx_surge' => [
            'label' => 'krx_surge · krx_surge_day · krx_surge_event',
            'title' => '최고 거래대금 신호',
            'what'  => '<code>krx_surge</code>=날짜별 신호 <b>캐시</b> · <code>krx_surge_day</code>=계산한 날 마커 '
                     . '(「신호 0개인 날」과 「아직 계산 안 한 날」을 가른다) · '
                     . '<code>krx_surge_event</code>=신호의 수명(<b>append-only</b>)',
            'grain' => '일별 파생',
            'src'   => '<code>krx_amt</code> 에서 계산 (외부 호출 없음)',
            'tasks' => ['dart_krx', 'dart_eod'],
            'ui'    => '캐시에 없는 날을 열면 <b>그 화면이 그 자리에서 계산해</b> 채운다(첫 조회자만 몇 초를 낸다)',
            'keep'  => '캐시 둘은 매일 범위 삭제 · <code>krx_surge_event</code> 는 <b>지우지 않는다</b>',
            'probe' => 'krx_surge',
            'warn'  => [
                '★<code>invalidateSurge()</code> 에 <b><code>krx_surge_event</code> 삭제를 넣지 않는다</b> — '
                . '그 표는 캐시가 매일 지워지는 것을 견디라고 따로 있다.',
                '★포트폴리오 로직에서 이 표를 읽을 때는 반드시 <code>pf_position.surge_event_d</code> 를 경유한다. '
                . '<code>SELECT MAX(d)</code> 로 직접 읽으면 경보가 「내가 산 이유였던 박스」가 아니라 '
                . '「가장 최근에 뜬 아무 박스」를 뜻하게 된다.',
            ],
        ],

        'stock_financial' => [
            'label' => 'stock_financial · stock_fundamental',
            'title' => '재무제표',
            'what'  => '(종목, 사업연도, 보고서) 당 1행 — 매출·영업이익·순이익·자본. 분기는 <b>누적(YTD)</b>',
            'grain' => '분기·연간',
            'src'   => 'DART OpenAPI',
            /* 매일 몫은 dart_fresh 하나다. 나머지 셋은 SSH 전용(최초 적재·결측 보수)이지만
             * 이 표에 <b>쓰는</b> 것은 맞으므로 함께 적는다 — 「누가 이 값을 바꾸나」가 이 칸의 질문이다. */
            'tasks' => ['dart_fresh', 'dart_quarter', 'dart_shares', 'dart_nifix'],
            'keep'  => '2016~ 전부 보관',
            'probe' => 'stock_financial',
            'warn'  => [
                '★창을 계산하지 않고 <b>최신 5슬롯을 매일 통째로 다시 받는다</b>. '
                . '공시는 마감일까지 97% 만 들어오고 나머지가 그 뒤 2주에, 비12월결산사는 연중 아무 때나 낸다.',
                '★주요계정 API(<code>fnlttMultiAcnt</code>)는 <b>순이익을 빠뜨린다</b> — '
                . '회사가 표준계정으로 태깅한 것만 주기 때문이다. <code>fnlttSinglAcntAll</code> 로 메운다.',
                '★★별도(OFS) 값으로 연결(CFS) 행을 메우지 않는다 — 빈칸이 틀린 값보다 낫다.',
            ],
        ],

        'stock_daily_range' => [
            'label' => 'stock_daily_range → stock_price_range',
            'title' => '기간 고가·저가',
            'what'  => '원본은 (종목, 거래일) 당 장중 고·저 1행. <code>stock_price_range</code> 는 '
                     . '그것을 <b>6개월로 접은 캐시</b>(고가·저가·그 날짜·쓴 봉 수)',
            'grain' => '일별 원본 + 집계 캐시',
            'src'   => '네이버 폴링 API(오늘 한 줄) · 씨뿌리기만 일봉 API',
            'tasks' => ['dart_eod'],
            'keep'  => '원본은 6개월+α, 오래된 행 정리',
            'probe' => 'stock_daily_range',
            'warn'  => [
                '★★<b>원본을 쌓는 것이 요점이다.</b> 집계만 저장하면 창 밖으로 나간 옛 고점이 안 빠져서 '
                . '전종목 재수집이 주기적으로 필요해지는데, 일봉 API 는 묶음 호출이 없어 2,766콜·340초라 '
                . '30초에 못 들어간다. 원본을 두면 <b>창이 스스로 흐른다</b>.',
                '★<b>현재가는 여기 담지 않는다</b> — 낙폭은 볼 때 계산한다(담으면 장중에 안 따라온다).',
                '★거래정지일은 시·고·저·거래량이 0 이고 종가만 온다 → 종가를 고·저로 쓴다.',
            ],
        ],

        'pf_daily' => [
            'label' => 'pf_daily',
            'title' => '보유·관심 종목 일봉 이력',
            'what'  => '(종목, 날짜) 당 OHLCV. <b>보유 + 관심 종목만</b> (전종목이 아니다)',
            'grain' => '일별 · 약 3년',
            'src'   => '네이버 일봉 (종목당 1콜 · 증분)',
            'tasks' => ['dart_eod', 'dart_daily'],
            'keep'  => '지우지 않는다',
            'probe' => 'pf_daily',
            'warn'  => [
                '★<b>왜 따로 쌓나</b> — 시장 신호(이동평균·RSI·52주 위치·거래량 급증)는 이력이 있어야 계산되는데 '
                . '<code>all_stock_info</code> 는 스냅샷, <code>krx_daily</code> 는 10일뿐이다.',
                '★네이버 일봉은 <b>수정주가</b>다(액면분할이 소급 반영). 그래서 −98% 짜리 가짜 급락이 안 뜨지만, '
                . '조정 소수점이 남으므로 정수가 아니라 DECIMAL 로 받는다.',
                '★거래정지일(전부 0)도 <b>저장은 한다</b> — 빈 구멍을 만들지 않고, 걸러내는 일은 지표 계산이 맡는다.',
            ],
        ],

        'dt_min' => [
            'label' => 'dt_min · dt_min_log · dt_pool',
            'title' => '단타 1분봉 원장',
            'what'  => '(종목, 봉 시작시각) 당 OHLCV. <code>dt_min_log</code> 는 일자별 수집 상태, '
                     . '<code>dt_pool</code> 은 단타 풀(상한 20)',
            'grain' => '1분봉',
            'src'   => '키움 REST <code>ka10080</code> → 실패 시 네이버 (당일치)',
            'tasks' => ['dt_min'],
            'ui'    => '보유 종목의 초기 10거래일은 <b>단타 화면을 열 때</b> 화면이 하나씩 당겨 온다(<code>held_init</code>)',
            'keep'  => '★<b>최근 10거래일만</b> — 매일 그 밖을 지운다',
            'probe' => 'dt_min',
            'warn'  => [
                '★★<b>보관 창이 10거래일뿐이다.</b> 그 밖의 분봉은 매일 지워지고, 네이버는 7거래일까지만 주며 '
                . '그 뒤로는 어떤 파라미터로도 못 받는다. 장기 분봉이 필요해지면 「지금부터」 따로 쌓아야 한다.',
                '★수집 대상은 「단타 풀」이 아니라 <b>「풀 ∪ 보유」</b>다(<code>Dt::targetCodes()</code>). '
                . '보유를 <code>dt_pool</code> 에 <b>담지 않는다</b> — 그 표는 상한 20 을 FIFO 로 지우는 표라 '
                . '「＋」 한 번이 내가 산 종목의 봉을 통째로 지운다.',
                '★<b>봉 시각은 「시작」</b>이다(<code>Kiwoom::TS_BASE=\'start\'</code> · 실측 확정). '
                . '잘못 잡으면 전 데이터가 1분씩 밀리고 <b>조용히</b> 깨진다.',
                '★거래량 기준도 하나다 — <code>KIWOOM_NO_SOR=true</code>(KRX 단독). '
                . '<code>_AL</code>(SOR)은 NXT 를 더해 네이버의 1.49배가 되는데, 네이버가 폴백이자 장중 실시간 소스라 '
                . '한 종목의 하루 봉에 두 기준이 섞인다.',
                '★<b>만료 삭제는 반드시 수집 «다음»</b> 이다 — 순서가 뒤집히면 수집 실패한 날 삭제만 돌아 '
                . '보관 일수가 조용히 줄어든다.',
            ],
        ],

        'qm_bar' => [
            'label' => 'qm_bar · qm_event · qm_task · qm_feat',
            'title' => '급등주 분봉 아카이브',
            'what'  => '「하루 10% 이상 오른 종목」의 급등일 전후 10거래일 1분봉 — <b>지워지지 않는다</b>',
            'grain' => '1분봉 · 아카이브',
            'src'   => '키움 REST (<code>base_dt</code> 로 과거 구간을 직접 지정)',
            'tasks' => ['qm'],
            'keep'  => '<b>만료 삭제 없음</b> — 아카이브는 불변이다. 대신 용량 가드 '
                     . '<code>QM_MAX_MB</code>'
                     . (($g = quote_const_from_file('cron/qm_collect.php', 'QM_MAX_MB'))
                        ? '=' . number_format($g) . 'MB' : '')
                     . ' 를 넘으면 수집을 멈춘다',
            'probe' => 'qm_bar',
            'warn'  => [
                '★<code>qm_*</code> 는 <code>dt_*</code> 와 <b>조인하지 않는다</b> — 종목코드 문자열만 공유한다. '
                . '<code>dt_min</code> 은 매일 지우는 표라 아카이브를 거기 넣으면 prune 이 집어삼킨다.',
                '★★분봉 가격을 <code>krx_amt</code> 가격과 <b>견주지 않는다</b> — 분봉은 지금 기준 수정주가, '
                . '<code>krx_amt</code> 는 그 날 있던 값이라 무상증자·액면분할이 있으면 상수배로 어긋난다 '
                . '(실측 1,045건 중 57건).',
                '★아카이브에 네이버 폴백을 쓰지 않는다 — 네이버는 「가장 최근 거래일 하루치」만 주므로 '
                . '두 달 전 구간엔 무용한데, <b>폴백이 성공하면 목표 날짜가 비었는데도 성공으로 보인다</b>.',
            ],
        ],
    ];
}

// ══════════════════════════════════════════════════════════════════════════
//  3. 화면별 시세 지도
// ══════════════════════════════════════════════════════════════════════════
/**
 * 다섯 화면이 각 항목을 어디서 읽나.
 *
 *   rows: [보이는 것, 원천(표 키 또는 'live'), 갱신 경로, 실효 신선도]
 *   원천이 <code>live:</code> 로 시작하면 <b>DB 를 거치지 않고</b> 화면이 그 자리에서 받는 것이다.
 */
function quote_screens(): array
{
    $age = NaverFinanceAPI::QUOTE_MAX_AGE;

    return [

        'etf' => [
            'label' => '주식ETF분석',
            'href'  => '/etf_stock.php?mode=ef',
            'note'  => 'ETF 와 그 편입종목을 서로 되짚어 보는 화면. 시세는 전부 DB(스냅샷)에서 읽는다.',
            'rows'  => [
                ['종목 현재가·등락률·시가총액', 'all_stock_info',
                 '크론 <code>stock_news</code>(평일 9회) + <code>dart_eod</code>(마감 메우기)',
                 '★장중 정규 fire 가 09·11·13·15시 넷뿐 — <b>최대 약 2시간</b>'],
                ['ETF 현재가·등락률·거래대금', 'all_etf_price',
                 '크론 <code>stock_news</code> 의 <b>정규 fire 만</b>', '★장중 최대 2시간 · 마감 뒤 15:05 값'],
                ['ETF 편입종목·비중', 'all_etf_holdings_info', '크론 <code>etf_update</code>', '하루 1회 (16:20~16:35)'],
                ['상단 「마지막 갱신」 글자', 'data_update_status',
                 '<code>stock_news</code> 가 끝나면서 <code>all_data_from_naver</code> 키를 찍는다',
                 '★정규·NXT 회차를 <b>안 가린다</b>'],
            ],
        ],

        'short' => [
            'label' => '단타',
            'href'  => '/stock/index.php?mode=short',
            'note'  => '사이트에서 <b>유일하게 분봉을 DB 에 쌓는</b> 화면이고, 시세 경로가 가장 복잡하다 — '
                     . '원장(과거)·화면 폴링(당일)·즉석 수집(미리보기)이 한 화면에 함께 있다. '
                     . '★<b>차트와 목록이 서로 다른 시계로 돈다</b>(아래 두 줄).',
            'rows'  => [
                ['목록 현재가·등락률·시총·<b>회전율</b>', 'all_stock_info',
                 '★서버가 응답 전에 <code>Dt::refreshQuotesLive()</code> 를 지난다 — '
                 . '<b>수집 대상만</b> 키움 <code>ka10095</code> 한 요청(실측 34종목 0.03초)',
                 Dt::TICK_SEC . '초 (<code>Dt::TICK_SEC</code>)'],
                ['메인 1분봉 · 보조 3분봉 (담은 종목)', 'dt_min',
                 '크론 <code>dt_min</code>(전일까지) + 장중엔 <b>키움 <code>ka10080</code></b> 으로 당일분을 덧댐'
                 . '(<code>Dt::todayBars()</code> · 메인·보조가 캐시를 나눠 써 <b>콜 1회</b>)',
                 '원장 전일까지 · 당일분 ' . Dt::TICK_SEC . '초 (보조는 60초)'],
                ['미리보기 분봉 (안 담은 종목)', 'live:네이버 당일 1분봉',
                 '<code>stock_analysis_api.php?module=stock&action=minute</code>', '즉시 (당일치뿐)'],
                ['일봉 패널', 'live:<b>키움 <code>ka10081</code></b>(대상 종목) / 네이버(그 밖)',
                 '<code>…&action=daily</code> — <b>경로는 그대로</b>이고 안에서 소스만 가른다. '
                 . '키움은 <b>거래대금까지 직접</b> 줘서 장중에도 실제값이다',
                 '즉시'],
                ['거래일 판정 · 보관 창', 'krx_amt', '크론 <code>dart_eod</code> 가 오늘 행을 넣는다',
                 '★이것 때문에 수집 크론이 16:45 다'],
                ['목록 배지 (퀀트·급등⚠·박스)', 'krx_amt · krx_surge', '크론 <code>dart_krx</code>·<code>dart_eod</code>',
                 '당일 잠정 → 익일 확정'],
                ['목록 배지 (ETF 편입 수)', 'all_etf_holdings_info', '크론 <code>etf_update</code>', '하루 1회'],
            ],
        ],

        'pf' => [
            'label' => '포트폴리오',
            'href'  => '/stock/index.php',
            'note'  => '이미 산 종목을 보는 자리라 <b>지금 값</b>과 <b>그 뒤 흐름</b> 둘만 있으면 된다. '
                     . '탐색 판정(퀀트신호·트리거)은 여기 세우지 않는다.',
            'rows'  => [
                ['보유 현재가·평가손익·수익률', 'all_stock_info',
                 '화면에 들어올 때 <b>보유 종목만</b> 갱신(<code>pf_load_calc()</code>) → '
                 . '<code>pf_stock.last_price</code> 로 동기화',
                 "{$age}초 (<code>QUOTE_MAX_AGE</code>) · 그보다 새것이면 콜 없음"],
                ['오늘의 신호 (이동평균·52주·거래량 급증)', 'pf_daily',
                 '크론 <code>dart_eod</code> 의 증분 수집', '전일 마감까지'],
                ['포지션 상세 차트', 'live:네이버 일봉 + <code>krx_amt</code>',
                 '<code>action=daily</code> — 목록과 같은 공용 경로', '즉시'],
                ['계단관통↓ 경보', 'krx_surge', '★<code>pf_position.surge_event_d</code> 를 <b>반드시 경유</b>',
                 '당일 잠정 → 익일 확정'],
                ['편입 당시 스냅샷', 'pf_entry_snapshot', '편입한 순간의 <b>기록</b>이라 갱신하지 않는다', '불변'],
                ['시뮬레이터 백테스트', 'pf_sim_data · pf_daily', '종목 등록 시 수집', '등록 시점'],
            ],
        ],

        'fund' => [
            'label' => '재무분석 · 관심종목',
            'href'  => '/stock/index.php?mode=fund',
            'note'  => '한 줄에 <b>세 시계</b>가 섞여 있다 — 재무(분기)·상장주식수(T+1)·현재가(지금). '
                     . 'PER 이 이상하면 대개 분모(상장주식수)가 낡은 것이다.',
            'rows'  => [
                ['현재가 · 시가총액', 'all_stock_info',
                 '크론 + 관심종목 화면은 진입할 때 담은 종목만 갱신', "{$age}초 / 크론 주기"],
                ['PER·PBR 분모(상장주식수) · 거래종목 판정', 'krx_daily', '크론 <code>dart_krx</code> (매일 13:05)',
                 'T+1 (전 거래일 확정치)'],
                ['매출·영업이익·순이익·자본', 'stock_financial', '크론 <code>dart_fresh</code> (평일 08:05)',
                 '최신 5슬롯을 매일 재수집'],
                ['6개월 고점대비·저점대비', 'stock_daily_range', '크론 <code>dart_eod</code> 의 <code>range</code> 단계',
                 '전일 마감 + 오늘 한 줄'],
                ['종목 상세의 6개월 고·저', 'live:네이버 일봉', '목록과 달리 <b>그 자리에서</b> 받는다', '즉시'],
                ['PER·PBR 밴드 차트', 'stock_financial · krx_amt',
                 '단위가 <b>시가총액</b>이라(<code>krx_amt.mktcap</code>) 액면분할이 저절로 무해하다. '
                 . '계단은 <b>공시 다음 거래일</b>에 꺾인다', '공시 반영 시'],
                ['어닝 서프라이즈(SUE)', 'dart_rcept · stock_financial · krx_amt',
                 '크론 <code>dart_fresh</code> 가 접수일 원장(<code>dart_rcept</code>)을 채운다',
                 '공시 접수 다음날 아침'],
            ],
        ],

        'quant' => [
            'label' => '퀀트',
            'href'  => '/stock/index.php?mode=quant',
            'note'  => '유일하게 <b>전종목 × 장기 이력</b>을 보는 화면이라, 다른 화면과 달리 원장(<code>krx_amt</code>) '
                     . '자체가 주인공이다. 신호는 그 원장에서 계산한 파생일 뿐이다.',
            'rows'  => [
                ['일별 거래대금·거래량·시총 (전종목)', 'krx_amt',
                 '확정: 크론 <code>dart_krx</code> 13:05 (<code>krx_daily</code>→이관 · <b>API 0회</b>)<br>'
                 . '잠정: 크론 <code>dart_eod</code> 15:50 (<code>all_stock_info</code> 스냅샷 · src=n)',
                 '당일 잠정(T+0) → 익일 확정(T+1)'],
                ['최고 거래대금 신호 목록', 'krx_surge',
                 '위 두 크론이 그 날을 다시 계산해 캐시에 넣는다', '크론과 같음'],
                ['신호 수명(추적 종료일)', 'krx_surge', '<code>krx_surge_event</code> — append-only', '지우지 않는다'],
                ['패턴분석·검증(백테스트)', 'krx_amt', '원장을 그 자리에서 집계', '조회 시점'],
                ['배지 판정 (매집형·박스·급등⚠)', 'krx_amt · krx_surge',
                 '판정 단일본 <code>stock/lib/quant.php</code> · 그리기 <code>style/quantbadge.js</code>', '원장과 같음'],
            ],
        ],
    ];
}

// ══════════════════════════════════════════════════════════════════════════
//  4. 외부 의존성
// ══════════════════════════════════════════════════════════════════════════
/** 어디가 끊기면 무엇이 멈추나 (CRON.md §4 와 같은 내용을 시세 관점으로 추린 것) */
function quote_externals(): array
{
    return [
        ['네이버 금융', 'finance · polling · api · fchart · m.stock',
         '공식 한도 없음 · <b>IP 차단 위험</b> → 호출 간격 100ms 고정',
         '★<b>사이트 전 주가가 멈춘다.</b> 다섯 화면 전부'],
        ['KRX 오픈API', 'data-dbg.krx.co.kr',
         '인증키 ≠ 서비스권한(서비스별 신청) · T+1 · 보관 11년 이상',
         '상장주식수 → PER 분모 · 퀀트 원장의 확정치'],
        ['DART OpenAPI', 'opendart.fss.or.kr',
         '하루 20,000회 (<code>dart_fresh</code> 는 200회 = 1%)',
         '재무 스크리너·SUE 갱신'],
        ['키움 REST', 'api.kiwoom.com',
         'TR 별 <b>1 req/s</b> · 429 백오프 · 분봉 1년 보관 · <b>IP 화이트리스트</b> · '
         . '<code>ka10095</code> 는 한 요청 <b>100종목</b>(150 은 <code>return_code=5</code> 로 거절)',
         '단타 분봉이 네이버 폴백(7거래일·당일 위주)으로 떨어지고, <b>장중 실시간 시세도 네이버로 넘어간다</b> '
         . '— 그때는 회전율이 다시 크론 주기로 남는다(네이버는 거래대금을 안 준다) · '
         . '급등주 아카이브는 아예 멈춘다'],
    ];
}

// ══════════════════════════════════════════════════════════════════════════
//  5. 실측 탐침 — 카탈로그가 아니라 「지금」
// ══════════════════════════════════════════════════════════════════════════
/**
 * 표별 현황을 한 번에 잰다.
 *
 * ★ 행 수는 <code>information_schema</code> 의 <b>추정치</b>를 쓴다(「≈」로 표시).
 *   <code>krx_amt</code>·<code>stock_daily_range</code> 는 수백만 행이라 정확한 <code>COUNT(*)</code> 가
 *   설정 화면 한 번 여는 값으로는 비싸다. 반면 <b>최신 시각</b>은 인덱스가 받쳐 즉시 나오고,
 *   이 화면이 정말 알고 싶은 것은 그쪽이다.
 *
 * @return array<string,array> probe 키 → ['rows'=>[[라벨,값,강조여부]...], 'err'=>?string]
 */
function quote_probe_all(PDO $pdo): array
{
    $out  = [];
    $meta = quote_table_meta($pdo);

    /** 한 줄짜리 스칼라 조회 — 실패해도 화면이 계속 뜬다 */
    $one = static function (string $sql, array $p = []) use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
        catch (Throwable $e) { return null; }
    };
    $row = static function (string $sql, array $p = []) use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    };

    // ── all_stock_info ──────────────────────────────────────────────────
    $r = $row("SELECT COUNT(*) AS n,
                      MAX(uDate) AS last_u,
                      COALESCE(SUM(DATE(uDate) = CURDATE()), 0) AS today
                 FROM all_stock_info");
    $n = (int)($r['n'] ?? 0);
    $t = (int)($r['today'] ?? 0);
    $out['all_stock_info'] = ['rows' => [
        ['종목 수',        $n ? number_format($n) . '종목' : '—'],
        ['가장 최근 갱신', quote_ago($r['last_u'] ?? null)],
        ['오늘 갱신',      $n ? number_format($t) . '종목 (' . round($t / $n * 100, 1) . '%)' : '—',
                           ($n && $t > 0 && $t / $n < 0.8)],
        ['그중 신선',      quote_stale_count($pdo, NaverFinanceAPI::QUOTE_MAX_AGE, $n)],
        ['표 크기',        quote_size($meta, 'all_stock_info')],
    ]];

    /* ── ETF 두 표 ──
     * ★★<b>이 표들의 <code>uDate</code> 는 <code>DATE</code> 타입이라 시각이 없다</b>(실측 2026-08-06).
     *   <code>all_stock_info.uDate</code>(DATETIME)와 다르므로 「N시간 전」으로 읽으면 안 된다 —
     *   하루 종일 「00:00」으로 보여 밤새 안 받은 것처럼 읽힌다(실제로 그렇게 나왔다).
     *   갱신 «시각»의 정본은 <code>data_update_status</code> 다:
     *     all_data_from_naver  = 정규 경로(주식+ETF)가 마지막으로 돈 시각
     *     auto_etf_naver_update = 편입종목 크론이 마지막으로 끝난 시각 */
    $up = [];
    try {
        foreach ($pdo->query("SELECT data_key, update_time FROM data_update_status") as $x)
            $up[(string)$x['data_key']] = (string)$x['update_time'];
    } catch (Throwable $e) { }

    // ── all_etf_price ───────────────────────────────────────────────────
    $r = $row("SELECT COUNT(*) AS n, MAX(uDate) AS last_u FROM all_etf_price");
    $out['all_etf_price'] = ['rows' => [
        ['ETF 수',        (int)($r['n'] ?? 0) ? number_format((int)$r['n']) . '개' : '—'],
        ['적재 날짜',     quote_date($r['last_u'] ?? null), quote_is_old_date($r['last_u'] ?? null, 3)],
        ['정규 경로 시각', quote_ago($up['all_data_from_naver'] ?? null)
                          . ' <span class="muted">(주식과 공용)</span>'],
        ['표 크기',       quote_size($meta, 'all_etf_price')],
    ]];

    // ── all_etf_holdings_info ───────────────────────────────────────────
    $r = $row("SELECT COUNT(DISTINCT etf_code) AS e, COUNT(*) AS n, MAX(uDate) AS last_u
                 FROM all_etf_holdings_info");
    $out['all_etf_holdings_info'] = ['rows' => [
        ['ETF 수',    (int)($r['e'] ?? 0) ? number_format((int)$r['e']) . '개' : '—'],
        ['편입 행',   (int)($r['n'] ?? 0) ? number_format((int)$r['n']) . '행' : '—'],
        ['적재 날짜', quote_date($r['last_u'] ?? null), quote_is_old_date($r['last_u'] ?? null, 4)],
        ['크론 완료', quote_ago($up['auto_etf_naver_update'] ?? null)],
        ['표 크기',   quote_size($meta, 'all_etf_holdings_info')],
    ]];

    /* ── data_update_status ──
     * 화면(주식ETF분석)의 「마지막 갱신」 글자가 읽는 표. 살아 있는 키는 셋뿐이고
     * <code>all_stock_info</code>·<code>all_etf_price</code> 키는 2026-05 에 멈춘 <b>죽은 키</b>다
     * (지금은 아무도 안 찍는다) — 그것을 새 화면의 원천으로 삼지 말라고 함께 보여 준다. */
    $out['data_update_status'] = ['rows' => []];
    foreach ([
        'all_data_from_naver'   => '정규 경로 (주식+ETF)',
        'all_data_from_nxt'     => 'NXT 경로 (604종목)',
        'auto_etf_naver_update' => 'ETF 편입종목',
    ] as $key => $lbl) {
        $out['data_update_status']['rows'][] = [$lbl, quote_ago($up[$key] ?? null)];
    }
    $dead = array_diff(array_keys($up), ['all_data_from_naver', 'all_data_from_nxt', 'auto_etf_naver_update']);
    $out['data_update_status']['rows'][] = ['죽은 키',
        $dead ? count($dead) . '개 <span class="muted">(' . pf_h(implode(', ', array_slice($dead, 0, 4)))
                . (count($dead) > 4 ? ' 외' : '') . ')</span>'
              : '없음'];

    // ── krx_daily ───────────────────────────────────────────────────────
    $d = $one("SELECT MAX(bas_dd) FROM krx_daily");
    $out['krx_daily'] = ['rows' => [
        ['최근 기준일',  $d ? quote_date($d) : '—', quote_is_old_date($d, 3)],
        ['그 날 종목 수', $d ? number_format((int)$one("SELECT COUNT(*) FROM krx_daily WHERE bas_dd = ?", [$d])) . '종목' : '—'],
        ['남아 있는 날', number_format((int)$one("SELECT COUNT(DISTINCT bas_dd) FROM krx_daily")) . '일 (prune 10일)'],
        ['표 크기',      quote_size($meta, 'krx_daily')],
    ]];

    // ── krx_amt ─────────────────────────────────────────────────────────
    $d = $one("SELECT MAX(d) FROM krx_amt");
    $srcTxt = '—';
    if ($d) {
        $s = [];
        try {
            $st = $pdo->prepare("SELECT src, COUNT(*) n FROM krx_amt WHERE d = ? GROUP BY src");
            $st->execute([$d]);
            foreach ($st as $x) {
                $lbl = ($x['src'] === 'k') ? 'KRX 확정' : (($x['src'] === 'n') ? '네이버 잠정' : $x['src']);
                $s[] = $lbl . ' ' . number_format((int)$x['n']);
            }
        } catch (Throwable $e) { }
        $srcTxt = $s ? implode(' · ', $s) : '—';
    }
    $out['krx_amt'] = ['rows' => [
        ['최근 거래일',   $d ? quote_date($d) : '—', quote_is_old_date($d, 3)],
        ['그 날 적재',    $srcTxt],
        ['원장 시작일',   quote_date($one("SELECT MIN(d) FROM krx_amt"))],
        ['행 수 (추정)',  quote_rows($meta, 'krx_amt')],
        ['표 크기',       quote_size($meta, 'krx_amt')],
    ]];

    // ── krx_surge ───────────────────────────────────────────────────────
    $r = $row("SELECT d, n, computed_at FROM krx_surge_day ORDER BY d DESC LIMIT 1");
    $out['krx_surge'] = ['rows' => [
        ['최근 계산일',   isset($r['d']) ? quote_date($r['d']) : '—'],
        ['그 날 신호 수', isset($r['n']) ? number_format((int)$r['n']) . '건' : '—'],
        ['계산 시각',     quote_ago($r['computed_at'] ?? null)],
        ['이벤트 원장',   quote_rows($meta, 'krx_surge_event') . ' (append-only)'],
    ]];

    // ── stock_financial ─────────────────────────────────────────────────
    $r = $row("SELECT COUNT(*) AS n, MAX(updated_at) AS last_u FROM stock_financial");
    $out['stock_financial'] = ['rows' => [
        ['행 수',          (int)($r['n'] ?? 0) ? number_format((int)$r['n']) . '행' : '—'],
        ['가장 최근 갱신', quote_ago($r['last_u'] ?? null)],
        ['최신 사업연도',  (string)($one("SELECT MAX(bsns_year) FROM stock_financial") ?: '—')],
        ['표 크기',        quote_size($meta, 'stock_financial')],
    ]];

    // ── stock_daily_range / stock_price_range ───────────────────────────
    $sdr = $one("SELECT MAX(bas_dd) FROM stock_daily_range");
    $out['stock_daily_range'] = ['rows' => [
        ['원본 최근 거래일', quote_date($sdr), quote_is_old_date($sdr, 3)],
        ['원본 행 수(추정)', quote_rows($meta, 'stock_daily_range')],
        ['6개월 캐시 갱신',  quote_ago($one("SELECT MAX(updated_at) FROM stock_price_range"))],
        ['6개월 캐시 종목',  number_format((int)$one("SELECT COUNT(*) FROM stock_price_range WHERE months = 6")) . '종목'],
        ['표 크기',          quote_size($meta, 'stock_daily_range')],
    ]];

    // ── pf_daily ────────────────────────────────────────────────────────
    $r = $row("SELECT COUNT(DISTINCT stock_code) AS c, COUNT(*) AS n, MIN(d) AS d0, MAX(d) AS d1 FROM pf_daily");
    $out['pf_daily'] = ['rows' => [
        ['대상 종목',   (int)($r['c'] ?? 0) ? number_format((int)$r['c']) . '종목 (보유+관심)' : '—'],
        ['최근 거래일', quote_date($r['d1'] ?? null), quote_is_old_date($r['d1'] ?? null, 3)],
        ['보관 구간',   ($r['d0'] ?? null) ? quote_date($r['d0']) . ' ~ ' . quote_date($r['d1']) : '—'],
        ['행 수',       (int)($r['n'] ?? 0) ? number_format((int)$r['n']) . '행' : '—'],
    ]];

    /* ── dt_min ──
     * ★<code>MIN(ts)</code>·<code>MAX(ts)</code> 로 묻는다 — <code>MIN(DATE(ts))</code> 로 감싸면
     *   함수가 인덱스(<code>ix_ts</code>)를 못 타 매번 전 표를 훑는다. 설정 화면 한 번 여는 값이 아니다. */
    $r = $row("SELECT COUNT(DISTINCT code) AS c, MIN(ts) AS d0, MAX(ts) AS d1 FROM dt_min");
    $today = $row("SELECT COUNT(*) AS n, COUNT(DISTINCT code) AS c
                     FROM dt_min WHERE ts >= CURDATE() AND ts < CURDATE() + INTERVAL 1 DAY");
    $pool  = $row("SELECT COALESCE(SUM(active = 1), 0) AS a, COUNT(*) AS n FROM dt_pool");
    $out['dt_min'] = ['rows' => [
        ['원장 종목',    (int)($r['c'] ?? 0) ? number_format((int)$r['c']) . '종목' : '—'],
        ['보관 창',      ($r['d0'] ?? null) ? quote_date($r['d0']) . ' ~ ' . quote_date($r['d1'])
                                             . ' (' . Dt::WINDOW_DAYS . '거래일)' : '—'],
        ['오늘 봉',      (int)($today['n'] ?? 0)
                            ? number_format((int)$today['n']) . '봉 / ' . (int)$today['c'] . '종목' : '없음'],
        ['단타 풀',      (int)($pool['a'] ?? 0) . '종목 (상한 ' . Dt::POOL_MAX . ')'],
        ['행 수 (추정)', quote_rows($meta, 'dt_min')],
        ['표 크기',      quote_size($meta, 'dt_min')],
    ]];

    /* ── qm_bar ──
     * ★<code>qm_bar</code> 에는 <code>ts</code> 단독 인덱스가 없다(PK 가 <code>code,ts</code>).
     *   그래서 구간·진척을 <b>봉 표가 아니라 과제 표(<code>qm_task</code>)와 이벤트 표에 묻는다</b> —
     *   둘 다 작고 인덱스가 있으며, 애초에 「어디까지 받았나」의 정본이 그쪽이다. */
    $r = $row("SELECT COUNT(*) AS n, COALESCE(SUM(state = 2), 0) AS done,
                      MIN(d) AS d0, MAX(d) AS d1 FROM qm_task");
    $n = (int)($r['n'] ?? 0);
    $dn = (int)($r['done'] ?? 0);
    /* ★가드 값을 손으로 적지 않는다 — 소스에서 읽는다(quote_const_from_file 주석 참조).
     *   판정 기준도 그쪽과 같은 data_length+index_length 다. */
    $guard = quote_const_from_file('cron/qm_collect.php', 'QM_MAX_MB');
    $mb    = isset($meta['qm_bar']) ? $meta['qm_bar']['sz'] / 1048576 : null;
    $out['qm_bar'] = ['rows' => [
        ['이벤트',        number_format((int)$one("SELECT COUNT(*) FROM qm_event")) . '건'],
        ['수집 구간',     ($r['d0'] ?? null) ? quote_date($r['d0']) . ' ~ ' . quote_date($r['d1']) : '—'],
        ['과제 진척',     $n ? number_format($dn) . ' / ' . number_format($n)
                              . ' (' . round($dn / $n * 100, 1) . '%)' : '아직 없음'],
        ['봉 수 (추정)',  quote_rows($meta, 'qm_bar')],
        ['표 크기',       quote_size($meta, 'qm_bar')
                          . ($guard ? ' <span class="muted">/ 가드 ' . number_format($guard) . ' MB</span>' : ''),
                          ($guard && $mb !== null && $mb >= $guard)],
    ]];

    return $out;
}

/** information_schema 한 방 — 행 수 추정치와 용량 (표마다 따로 물으면 느리다) */
function quote_table_meta(PDO $pdo): array
{
    try {
        $st = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS SZ
                             FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE()");
        $m = [];
        foreach ($st as $r) $m[(string)$r['TABLE_NAME']] = ['rows' => (int)$r['TABLE_ROWS'], 'sz' => (int)$r['SZ']];
        return $m;
    } catch (Throwable $e) { return []; }
}

function quote_rows(array $meta, string $t): string
{
    if (!isset($meta[$t])) return '—';
    return '≈ ' . number_format($meta[$t]['rows']) . '행';
}

function quote_size(array $meta, string $t): string
{
    if (!isset($meta[$t])) return '—';
    $mb = $meta[$t]['sz'] / 1048576;
    return ($mb >= 1024) ? round($mb / 1024, 2) . ' GB' : round($mb, 1) . ' MB';
}

/** 「몇 분 전」 — 시세 화면에서는 절대시각보다 이쪽이 먼저 눈에 들어온다 */
function quote_ago($dt): string
{
    if (!$dt) return '—';
    $ts = strtotime((string)$dt);
    if (!$ts) return (string)$dt;
    $s = time() - $ts;
    if ($s < 0)    return date('m-d H:i', $ts) . ' <span class="muted">(미래)</span>';
    if ($s < 60)   return date('H:i', $ts) . ' <b>(방금)</b>';
    if ($s < 3600) return date('H:i', $ts) . ' <b>(' . floor($s / 60) . '분 전)</b>';
    if ($s < 86400 * 2) return date('m-d H:i', $ts) . ' <b>(' . floor($s / 3600) . '시간 전)</b>';
    return date('Y-m-d H:i', $ts) . ' <b>(' . floor($s / 86400) . '일 전)</b>';
}

function quote_date($d): string
{
    if (!$d) return '—';
    $ts = strtotime((string)$d);
    if (!$ts) return (string)$d;
    $days = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $ts))) / 86400);
    $w = ['일','월','화','수','목','금','토'][(int)date('w', $ts)];
    return date('Y-m-d', $ts) . "({$w})" . ($days > 0 ? ' <span class="muted">' . $days . '일 전</span>' : '');
}

/** 마지막 갱신이 $sec 를 넘었나 (강조용) */
function quote_is_old($dt, int $sec): bool
{
    if (!$dt) return true;
    $ts = strtotime((string)$dt);
    return !$ts || (time() - $ts) > $sec;
}

/** 기준일이 $days 일보다 오래됐나 — 주말·연휴를 감안해 넉넉히 본다 */
function quote_is_old_date($d, int $days): bool
{
    if (!$d) return true;
    $ts = strtotime((string)$d);
    return !$ts || (strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $ts))) > $days * 86400;
}

/**
 * 지금 몇 종목이 <b>신선</b>한가 — <code>refreshQuotes()</code> 와 <b>같은 판정</b>을 세어 본다.
 *
 * ★ 판정 규칙을 여기 다시 적지 않으려 했지만, 그 함수는 세어 주지 않고 <b>받아 온다</b>(네이버를 두드린다).
 *   설정 화면을 여는 것만으로 콜이 나가면 안 되므로 <b>세기만</b> 한다 — 규칙이 바뀌면 여기도 함께 고친다.
 *
 * ★「낡음」이 아니라 「신선」을 센다. 실측(2026-08-06 10:15)에서 낡음이 2,734/2,751 로 나왔는데,
 *   바로 위 칸의 「오늘 갱신 95.6%」와 나란히 놓이면 <b>둘이 싸우는 것처럼 읽힌다</b>.
 *   실은 싸우는 것이 아니라 <b>이 화면이 말하려는 바로 그 사실</b>이다 —
 *   오늘 값이긴 한데 09:05 크론 것이라 이미 70분 묵었고, 신선한 몇 종목은
 *   포트폴리오 화면이 들어오면서 스스로 메운 보유분이다. 그래서 「그중 신선 N종목」으로 적는다.
 */
function quote_stale_count(PDO $pdo, int $maxAge, int $total = 0): string
{
    try {
        $now   = time();
        $close = strtotime(date('Y-m-d') . ' ' . NaverFinanceAPI::MARKET_CLOSE);
        $after = ($now > $close);
        $cut   = date('Y-m-d H:i:s', $after ? $close : ($now - $maxAge));
        $st = $pdo->prepare("SELECT COUNT(*) FROM all_stock_info WHERE uDate IS NOT NULL AND uDate >= ?");
        $st->execute([$cut]);
        $n = (int)$st->fetchColumn();
        $why = $after ? NaverFinanceAPI::MARKET_CLOSE . ' 이후' : "최근 {$maxAge}초";
        return number_format($n) . ($total ? ' / ' . number_format($total) : '') . '종목'
             . ' <span class="muted">(' . $why . ')</span>';
    } catch (Throwable $e) { return '—'; }
}
?>
