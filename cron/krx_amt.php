<?php
/**
 * cron/krx_amt.php — KRX 일별 <b>실제 거래대금</b> 수집
 *
 * 왜 필요한가 (2026-07-31 실측으로 확정)
 *   네이버 일봉에는 거래대금이 없어 지표 엔진이 「종가×거래량」으로 근사해 왔다.
 *   KRX 실제값과 대조하니 VWAP 대비 중앙 오차 0.99%, 두 날 대소 비교 뒤집힘 0.79% —
 *   「전고 거래대금 돌파」 판정이 갈릴 수 있다. 그래서 실제값을 받아 둔다.
 *
 * job
 *   status                     적재 현황
 *   daily                      최근 거래일 1일치 (크론용 · 2초) — KRX 오픈API(일자별 전종목)
 *   item  years=4              ★대상 종목만 <b>종목당 1회</b>로 4년치 (공공데이터포털 · 권장)
 *   backfill from=YYYYMMDD [to=] [all=1] [sec=초] [min=500]
 *                              구간을 훑어 내려가며 채운다 (KRX 일자별 · 1일당 ~2초)
 *
 * ★ 비용은 「날짜 수」로만 정해진다 — KRX API 가 일자별 전종목이라 종목을 좁혀도 호출은 안 준다.
 *   그래서 <b>받는 건 전종목, 저장은 대상 종목만</b>(포트폴리오·관심·시뮬레이터)이 기본이다.
 *   all=1 이면 전종목을 저장한다.
 *
 * ★ 이어받기에 커서 파일이 없다 — <b>저장된 사실 자체가 진행 상황</b>이다.
 *   `min=` 이상 종목이 이미 들어 있는 날은 건너뛰므로, 중단됐으면 같은 명령을 다시 치면 된다.
 *
 * SSH 실행 예
 *   php cron/krx_amt.php job=backfill from=20240801 all=1 sec=600   ← 2년치 전종목, 10분씩 끊어서
 *   php cron/krx_amt.php job=backfill days=250 sec=600              ← 대상 종목만 1년치씩
 *   php cron/krx_amt.php job=daily
 */
require_once __DIR__ . '/_boot.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/krx.inc';
// 공공데이터포털 키 (없으면 job=item 만 못 쓴다 — 나머지는 그대로 동작)
@include_once $_SERVER['DOCUMENT_ROOT'] . '/env/datago.inc';

$CLI = (PHP_SAPI === 'cli');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

// 웹으로 열 때만 토큰을 본다. CLI 는 서버에 들어와야 돌릴 수 있으니 그 자체가 자격이다.
$TOKEN = 'econ-krx-amt';
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

function say(string $s): void { echo $s . "\n"; @flush(); }

$job  = (string)($_GET['job'] ?? 'status');
$days = max(1, min(3000, (int)($_GET['days'] ?? 1)));
$sec  = max(0, (int)($_GET['sec'] ?? 0));          // 시간 예산(초) — 0 이면 무제한
$all  = (int)($_GET['all'] ?? 0) === 1;
$gap  = max(0, min(2000, (int)($_GET['gap'] ?? 120)));   // 호출 간격(ms) — KRX 예의

$krx = new KrxAmt($pdo);
if (!$krx->hasKey()) { say('KRX 인증키가 없습니다 (env/krx.inc).'); exit; }
$krx->ensureTable();

$codes = $all ? null : $krx->targets();
$t0 = microtime(true);

switch ($job) {

    case 'status': {
        [$n, $c, $mn, $mx] = $krx->stat();
        say('krx_amt: ' . number_format($n) . '행 · ' . $c . '종목 · '
            . ($n ? "{$mn} ~ {$mx}" : '(비어 있음)'));
        say('대상 종목(' . count($krx->targets()) . '): ' . implode(' ', $krx->targets()));
        break;
    }

    /* ── 매일 갱신 (크론) ──
     *   ★ 기본은 <b>krx_daily 에서 옮겨 담기</b> — API 를 부르지 않는다.
     *     13:05 `dart_krx` 크론이 이미 KRX 전종목(거래대금 포함)을 krx_daily 에 넣어 두는데,
     *     그건 10일 뒤 지워진다. 지워지기 전에 여기로 옮겨 장기 이력으로 만든다.
     *   ★ all=1 이면 krx_daily 의 전종목을 옮긴다 — 상승분석처럼 대상이 매일 바뀌는 화면까지 덮으려면 이쪽.
     *   ★ krx_daily 가 비었을 때만(그 크론이 실패했을 때) API 를 직접 부른다. */
    case 'daily': {
        $n = $krx->syncFromKrxDaily($all ? null : $codes);
        if ($n) { say("krx_daily 에서 {$n}행 옮김 (API 호출 0회)"); break; }

        say('krx_daily 가 비어 있어 KRX API 로 직접 받습니다.');
        $hit = 0;
        for ($back = 1; $back <= 6; $back++) {
            $ymd = date('Ymd', strtotime("-{$back} day"));
            try { $hit = $krx->collectDay($ymd, $codes); }
            catch (Throwable $e) { say("{$ymd} 실패: " . $e->getMessage()); continue; }
            if ($hit) { say("{$ymd}: {$hit}행 저장"); break; }
        }
        if (!$hit) say('최근 6일 안에 받을 거래일이 없습니다 (연휴이거나 아직 안 올라옴).');
        break;
    }

    /* ── ★ 대상 종목만 종목당 1회로 (공공데이터포털) ──
     *   KRX 오픈API 는 일자별 전종목이라 24종목을 채우려 해도 985일을 훑어야 했다.
     *   금융위 API 는 「종목 + 기간」으로 물을 수 있어 종목당 1회면 끝난다. 원천이 KRX 라 값도 같다. */
    case 'item': {
        $gov = new StockAmtGov($pdo);
        if (!$gov->hasKey()) {
            say('공공데이터포털 인증키가 없습니다 — env/datago.inc 에 DATA_GO_KEY 를 넣으세요.');
            say('  발급: https://www.data.go.kr/data/15094808/openapi.do (금융위원회_주식시세정보)');
            break;
        }
        $gov->ensureTable();
        $years = max(1, min(10, (int)($_GET['years'] ?? 4)));
        $only  = trim((string)($_GET['code'] ?? ''));
        $list  = $only !== '' ? [preg_replace('/[^0-9A-Za-z]/', '', $only)] : $codes;
        $from  = date('Ymd', strtotime("-{$years} year"));
        $to    = date('Ymd');
        say('대상 ' . count($list) . '종목 · ' . $from . ' ~ ' . $to . ' (' . $years . '년)');

        $tot = 0; $fail = 0;
        foreach ($list as $i => $c) {
            try {
                $n = $gov->collect($c, $from, $to);
                $tot += $n;
                say(sprintf('  [%2d/%2d] %s  %s행', $i + 1, count($list), $c, number_format($n)));
            } catch (Throwable $e) {
                $fail++;
                say(sprintf('  [%2d/%2d] %s  실패: %s', $i + 1, count($list), $c, $e->getMessage()));
                if ($fail >= 3 && $tot === 0) { say('연속 실패 — 키·승인 상태를 확인하세요.'); break; }
            }
            usleep(200000);   // 포털 예의 (초당 5건)
        }
        [$n2, $c2, $mn2, $mx2] = $krx->stat();
        say(sprintf('완료: %s행 저장 · %.0f초', number_format($tot), microtime(true) - $t0));
        say('krx_amt 현재: ' . number_format($n2) . '행 · ' . $c2 . '종목 · ' . $mn2 . ' ~ ' . $mx2);
        break;
    }

    /* ── 과거로 훑어 내려가기 (KRX 일자별) ──
     *
     * ★★ 2026-07-31 — <b>구간 지정(from/to)과 이어받기</b>를 넣었다.
     *   원래는 커서가 「저장된 min(d) 의 하루 전」 하나뿐이었다. 선별 24종목을 채울 때는
     *   그것으로 충분했지만, <b>전종목 원장</b>을 뒤늦게 채우려 하니 곧바로 막혔다 —
     *   min(d) 가 이미 2022-04-05(24종목분)이라 커서가 그보다 <b>더 과거</b>로만 내려가서
     *   정작 비어 있는 2024~2026 전종목 구간에는 영영 들어가지 못한다.
     *
     * ⇒ `from=`(가장 오래된 날) · `to=`(시작점, 기본 어제) 로 구간을 직접 준다.
     *   이어받기는 커서 대신 <b>그 날에 이미 몇 종목이 있나</b>(`min=`, 기본 500)로 판정한다.
     *   저장된 사실 자체가 진행 상황이라 커서 파일이 필요 없고, 몇 번을 다시 돌려도 안전하다.
     *
     * ★ KRX 보관 한도 실측(2026-07-31) — 문서에 적어 둔 「730일」은 <b>틀렸다</b>.
     *   1,460·1,825·2,190·2,555·3,285·4,015일 전을 찍어 보니 <b>11년 전(2015-08-03)까지 정상</b>이다.
     *   ⇒ 깊이를 서둘러 정할 이유가 없다. 얕게 시작해 필요하면 나중에 더 내려가면 된다.
     */
    case 'backfill': {
        $ymdArg = static fn(string $k): string => preg_replace('/[^0-9]/', '', (string)($_GET[$k] ?? ''));
        $toDate = static fn(string $s): int =>
            strtotime(substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2));

        $from = $ymdArg('from');
        $to   = $ymdArg('to');
        $min  = max(0, (int)($_GET['min'] ?? ($all ? 500 : 0)));   // 이 수 이상 있으면 받은 날로 본다
        if ($from !== '' && !isset($_GET['days'])) $days = 3000;   // 구간을 줬으면 날짜 수로 막지 않는다

        if (strlen($to) === 8)        $cur = $toDate($to);
        elseif ($from !== '')         $cur = strtotime('-1 day');
        else {
            [$n0, , $mn0, ] = $krx->stat();
            $cur = ($n0 && $mn0) ? strtotime($mn0 . ' -1 day') : strtotime('-1 day');
        }
        $stop = strlen($from) === 8 ? $toDate($from) : 0;

        say('시작: ' . date('Y-m-d', $cur) . ' 부터 뒤로 '
            . ($stop ? date('Y-m-d', $stop) . ' 까지' : $days . '일')
            . ' · 저장대상 ' . ($all ? '전종목' : count($codes) . '종목')
            . ($min ? " · 이미 {$min}종목 이상 있는 날은 건너뜀" : '')
            . ($sec ? " · 예산 {$sec}초" : ''));

        $done = 0; $rows = 0; $skip = 0; $have = 0; $fail = 0;
        for ($i = 0; $i < $days; $i++) {
            if ($sec && microtime(true) - $t0 > $sec) { say('시간 예산 도달 — 중단(다시 실행하면 이어받습니다)'); break; }
            if ($stop && $cur < $stop) { say('from 에 도달했습니다.'); break; }
            $ymd = date('Ymd', $cur);
            $d   = date('Y-m-d', $cur);
            $cur = strtotime('-1 day', $cur);
            if ((int)date('N', strtotime($ymd)) >= 6) { $skip++; continue; }   // 주말은 호출조차 안 한다
            if ($min && $krx->dayCount($d) >= $min) { $have++; continue; }     // 이미 받은 날
            try {
                $r = $krx->collectDay($ymd, $codes);
                $rows += $r; $done++;
                if ($r === 0) $skip++;                  // 휴장일
            } catch (Throwable $e) {
                $fail++;
                say($d . ' 실패: ' . $e->getMessage());
                if ($fail >= 10) { say('연속 실패가 많아 중단합니다.'); break; }
            }
            if ($done % 25 === 0 && $done) {
                say(sprintf('  … %s 까지 · %d일 처리 · %s행 · %.0f초',
                    $d, $done, number_format($rows), microtime(true) - $t0));
            }
            if ($gap) usleep($gap * 1000);
        }
        [$n, $c, $mn, $mx] = $krx->stat();
        say(sprintf('완료: %d일 처리(휴장·주말 %d · 이미있음 %d) · %s행 · %.1f분',
            $done, $skip, $have, number_format($rows), (microtime(true) - $t0) / 60));
        say('krx_amt 현재: ' . number_format($n) . '행 · ' . $c . '종목 · ' . $mn . ' ~ ' . $mx);
        break;
    }

    /* ── 신고가 신호 캐시 전체 예열 (SSH 1회 · ~15초) ──
     *   과거 날짜를 드롭다운으로 옮겨 다닐 때 날짜마다 몇 초씩 계산하지 않게 미리 채운다.
     *   창 길이(KrxAmt::SURGE_WIN)를 바꾼 뒤에도 이걸 한 번 돌리면 전 구간이 새 창으로 재계산된다. */
    case 'surge': {
        $from = preg_replace('/[^0-9-]/', '', (string)($_GET['from'] ?? '2025-01-01'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '2025-01-01';
        [$sig, $daysN] = $krx->warmSurgeAll($from);
        say(sprintf('신호 캐시 예열 — %s 부터 · 신호 %s건 · %d거래일 마킹 · %.0f초',
            $from, number_format($sig), $daysN, microtime(true) - $t0));
        break;
    }

    default:
        say('job=status | daily | item [years=4] [code=005380] | backfill days=N [all=1] [sec=초] [gap=ms] | surge [from=YYYY-MM-DD]');
}
?>
