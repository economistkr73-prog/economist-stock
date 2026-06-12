<?php
// stock_analysis_api.php — 상승종목 분석 대시보드 데이터 API (module=stock)
//   action=top30   상승률 상위 30  → [{code,name,price,rate,tradeEok}, ...]
//   action=news    종목 뉴스       → [{title,url,date}, ...]   (?code=005930)
//   action=daily   100일 일봉      → [{t:"YYYY-MM-DD",o,h,l,c,v}, ...]  (?code=)  ※다음 단계
//   action=minute  당일 1분봉      → [{t:"YYYY-MM-DD HH:MM",o,h,l,c,v}, ...] (?code=) ※다음 단계
//
// 규칙(STOCK_DASHBOARD_BRIEF):
//   - 모든 응답 Content-Type: application/json; charset=utf-8
//   - 빈/실패는 빈 배열 [] + HTTP 200, 진짜 에러만 {error:"..."}
// 부트스트랩은 place_api.php / schedule_api.php 패턴과 동일 (ob_start → 인증 → ob_clean → JSON).
ob_start(); // included 파일의 stray output 방지
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? 'stock';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($module) {
        case 'stock': api_stock($action, $pdo); break;
        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown module: {$module}"], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ==========================================================
// stock 모듈
// ==========================================================
function api_stock(string $action, PDO $pdo): void
{
    switch ($action) {

        // ── 상승률 상위 30 (기존 실시간 시세 테이블 all_stock_info 재사용) ──
        //   커서 페이징: before(상승률)·before_code(동률 보조키) 가 오면 그보다 '낮은' 30개.
        //   정렬은 stock_rate DESC, stock_code ASC 고정(동률에도 안정적 페이징).
        case 'top30': {
            // 정렬 기준: rate=상승률상위(기본), cap=시총상위
            $sort    = ($_GET['sort'] ?? 'rate') === 'cap' ? 'cap' : 'rate';
            $sortCol = $sort === 'cap' ? 'cap' : 'rate';   // 통합 서브쿼리의 alias

            // 주식 + ETF를 읽기 시점에만 UNION ALL로 통합 (테이블은 그대로 유지).
            //   단위는 둘 다 '억' 기준: 주식 stock_cap/stock_vol_cap, ETF market_cap/trading_value(÷1e8 적재).
            //   ETF 이름은 all_etf_price에 없어 all_etf_info JOIN. kind로 종류 구분(클릭/뱃지용).
            //   etfTop = 이 종목을 '상위 편입'한 ETF 수(all_stock_holdings_info.top_rank_count) → ETF 뱃지용
            $union = "SELECT s.stock_code AS code, s.stock_name AS name, s.stock_price AS price,
                             s.stock_rate AS rate, s.stock_cap AS cap, s.stock_vol_cap AS tradeEok,
                             'stock' AS kind,
                             COALESCE(sh.top_rank_count, 0) AS etfTop,
                             COALESCE(sh.etf_count, 0)      AS etfCnt
                      FROM all_stock_info s
                      LEFT JOIN all_stock_holdings_info sh ON sh.stock_code = s.stock_code
                      UNION ALL
                      SELECT ep.etf_code, ei.etf_name, ep.etf_price,
                             ep.etf_rate, ep.market_cap, ep.trading_value,
                             'etf' AS kind,
                             0 AS etfTop, 0 AS etfCnt
                      FROM all_etf_price ep
                      JOIN all_etf_info ei ON ei.etf_code = ep.etf_code";
            // 기준 컬럼이 양수인 행만 (시총/상승률 0 또는 미수집 제외)
            $base = "SELECT * FROM ({$union}) u WHERE u.{$sortCol} > 0";

            $before     = $_GET['before'] ?? '';
            $beforeCode = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['before_code'] ?? ''));

            if ($before === '' || !is_numeric($before)) {           // 첫 페이지
                $sql = "{$base} ORDER BY u.{$sortCol} DESC, u.code ASC LIMIT 30";
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } else {                                                // 다음 페이지(커서 이후)
                // ⚠️ 명명 플레이스홀더는 재사용 불가(에뮬레이션 OFF 환경) → :b1/:b2 분리
                $sql = "{$base}
                          AND (u.{$sortCol} < :b1 OR (u.{$sortCol} = :b2 AND u.code > :bc))
                        ORDER BY u.{$sortCol} DESC, u.code ASC LIMIT 30";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':b1' => (float)$before, ':b2' => (float)$before, ':bc' => $beforeCode]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $out = array_map(static function ($r) {
                return [
                    'code'     => (string) $r['code'],
                    'name'     => (string) $r['name'],
                    'price'    => (float)  $r['price'],
                    'rate'     => (float)  $r['rate'],
                    'cap'      => (float)  $r['cap'],
                    'tradeEok' => (float)  $r['tradeEok'],
                    'kind'     => (string) $r['kind'],
                    'etfTop'   => (int)    $r['etfTop'],     // 상위 편입 ETF 수
                    'etfCnt'   => (int)    $r['etfCnt'],     // 편입 ETF 총수
                ];
            }, $rows);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 종목 뉴스 (NaverFinanceAPI::getNaverFinanceNews 재사용) ──
        case 'news': {
            $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
            if ($code === '') { echo json_encode([]); return; }

            $api  = new NaverFinanceAPI();
            $news = $api->getNaverFinanceNews($code, 12);
            if (isset($news['error']) || !is_array($news)) { echo json_encode([]); return; }

            $out = [];
            foreach ($news as $n) {
                if (!isset($n['title'])) continue;
                $out[] = [
                    'title' => (string) $n['title'],
                    'url'   => (string) ($n['link'] ?? ''),
                    'date'  => (string) ($n['date'] ?? ''),
                ];
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 160일 일봉 (네이버 fchart siseJson 수집, 요청은 1회뿐이라 행 수 늘려도 속도 영향 없음) ──
        case 'daily': {
            $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
            if ($code === '') { echo json_encode([]); return; }

            $want = (int)($_GET['days'] ?? 160);
            $want = $want >= 240 ? 240 : 160;        // 허용: 160 / 240 영업일
            $cal  = $want >= 240 ? 380 : 250;        // 영업일 확보용 달력일수(주말·휴일 버퍼 포함)

            $api = new NaverFinanceAPI();
            $res = $api->getDailyOhlc($code, $cal);
            if (isset($res['error']) || !isset($res['success'])) { echo json_encode([]); return; }

            // 최근 N영업일만 (오름차순 유지)
            $rows = $res['success'];
            if (count($rows) > $want) $rows = array_slice($rows, -$want);
            echo json_encode(array_values($rows), JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 당일 1분봉 (네이버 모바일차트 API, 정상 OHLC) ──
        case 'minute': {
            $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
            if ($code === '') { echo json_encode([]); return; }

            $api = new NaverFinanceAPI();
            $res = $api->getMinuteOhlc($code);
            if (isset($res['error']) || !isset($res['success'])) { echo json_encode([]); return; }

            echo json_encode(array_values($res['success']), JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 최근 3거래일 rise_analysis 신호 (대시보드 뱃지용) ──
        //   반환: { "005930": {type,pred,latest,today,count,dates:[...]}, ... }
        case 'signals': {
            try {
                $rows = $pdo->query(
                    "SELECT stock_code, signal_date, signal_type, is_signal, pred_hit3
                     FROM rise_pick
                     WHERE signal_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                     ORDER BY signal_date DESC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { echo json_encode(new stdClass()); return; }

            $today = date('Y-m-d');
            $map = [];
            foreach ($rows as $r) {
                $c = $r['stock_code'];
                if (!isset($map[$c])) {   // 첫 등장 = 최신(정렬 desc)
                    $map[$c] = [
                        'type'   => $r['signal_type'],
                        'pred'   => $r['pred_hit3'] !== null ? (float)$r['pred_hit3'] : null,
                        'signal' => (int)$r['is_signal'],
                        'latest' => $r['signal_date'],
                        'today'  => 0, 'count' => 0, 'dates' => [],
                    ];
                }
                $map[$c]['count']++;
                $map[$c]['dates'][] = $r['signal_date'];
                if ($r['signal_date'] === $today) $map[$c]['today'] = 1;
            }
            echo json_encode($map ?: new stdClass(), JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 전일 신호: 오늘 이전 최근 3분석일을 날짜별 그룹으로 ──
        //   종목은 가장 최근 신호일 그룹에만 1회 표시, count=최근3일중 신호난 일수
        //   반환: { dates:[d1,d2,d3], groups:{ d1:[{...,count}], d2:[...], d3:[...] } }
        case 'prevsignals': {
            try {
                $dates = $pdo->query(
                    "SELECT DISTINCT signal_date FROM rise_pick WHERE signal_date < CURDATE()
                     ORDER BY signal_date DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
                if (!$dates) { echo json_encode(['dates' => [], 'groups' => new stdClass()]); return; }

                $in = implode(',', array_fill(0, count($dates), '?'));
                // 시세 출처 폴백: 주식=all_stock_info, ETF=all_etf_price (rise_pick에 ETF 섞여있음)
                $st = $pdo->prepare(
                    "SELECT rp.stock_code, rp.stock_name, rp.signal_date, rp.signal_type, rp.pred_hit3,
                            rp.today_rate, rp.close_price, rp.is_signal,
                            COALESCE(asi.stock_rate,  aep.etf_rate)  AS cur_rate,
                            COALESCE(asi.stock_price, aep.etf_price) AS cur_price,
                            CASE
                              WHEN asi.stock_cap  > 0 THEN asi.stock_vol_cap / asi.stock_cap  * 100
                              WHEN aep.market_cap > 0 THEN aep.trading_value / aep.market_cap * 100
                              ELSE NULL
                            END AS turnover
                     FROM rise_pick rp
                     LEFT JOIN all_stock_info asi ON asi.stock_code = rp.stock_code
                     LEFT JOIN all_etf_price  aep ON aep.etf_code   = rp.stock_code
                     WHERE rp.signal_date IN ({$in})");
                $st->execute($dates);

                $byCode = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $c = $r['stock_code'];
                    if (!isset($byCode[$c])) $byCode[$c] = ['count' => 0, 'recent' => '', 'row' => null];
                    $byCode[$c]['count']++;
                    if ($r['signal_date'] > $byCode[$c]['recent']) { $byCode[$c]['recent'] = $r['signal_date']; $byCode[$c]['row'] = $r; }
                }

                $groups = [];
                foreach ($dates as $d) $groups[$d] = [];
                foreach ($byCode as $c => $info) {
                    $r = $info['row'];
                    $groups[$info['recent']][] = [
                        'code' => $c, 'name' => $r['stock_name'], 'type' => $r['signal_type'],
                        'pred' => $r['pred_hit3'] !== null ? (float)$r['pred_hit3'] : null,
                        'rate' => (float)$r['today_rate'], 'close' => (int)$r['close_price'],
                        'cur'  => $r['cur_rate']  !== null ? (float)$r['cur_rate']  : null,   // 당일(실시간) 등락률
                        'curPrice' => $r['cur_price'] !== null ? (float)$r['cur_price'] : null,
                        'turnover' => $r['turnover'] !== null ? (float)$r['turnover'] : null, // 회전율(거래대금/시총)
                        'signal' => (int)$r['is_signal'], 'count' => $info['count'],
                    ];
                }
                foreach ($groups as &$g) {
                    usort($g, function ($a, $b) {
                        if ($a['signal'] !== $b['signal']) return $b['signal'] - $a['signal'];
                        if ($a['type'] !== $b['type']) return ($a['type'] === 'core' ? 0 : 1) - ($b['type'] === 'core' ? 0 : 1);
                        return ($b['pred'] ?? 0) <=> ($a['pred'] ?? 0);
                    });
                }
                unset($g);
                echo json_encode(['dates' => $dates, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) { echo json_encode(['dates' => [], 'groups' => new stdClass()]); }
            return;
        }

        // ── 종목 검색 (주식+ETF 통합, 코드/이름) → 차트·뉴스 열기용 행 그대로 반환 ──
        case 'search': {
            $kw = trim((string)($_GET['keyword'] ?? ''));
            if ($kw === '') { echo json_encode([]); return; }
            $like = '%'.$kw.'%';
            $sql = "SELECT * FROM (
                        SELECT stock_code AS code, stock_name AS name, stock_price AS price,
                               stock_rate AS rate, stock_cap AS cap, stock_vol_cap AS tradeEok, 'stock' AS kind
                        FROM all_stock_info
                        WHERE stock_code LIKE :k1 OR stock_name LIKE :k2
                        UNION ALL
                        SELECT ep.etf_code, ei.etf_name, ep.etf_price,
                               ep.etf_rate, ep.market_cap, ep.trading_value, 'etf' AS kind
                        FROM all_etf_price ep
                        JOIN all_etf_info ei ON ei.etf_code = ep.etf_code
                        WHERE ep.etf_code LIKE :k3 OR ei.etf_name LIKE :k4
                    ) u
                    ORDER BY CASE WHEN u.name = :ke THEN 1 ELSE 2 END, u.name ASC
                    LIMIT 12";
            $st = $pdo->prepare($sql);
            $st->execute([':k1'=>$like, ':k2'=>$like, ':k3'=>$like, ':k4'=>$like, ':ke'=>$kw]);
            $out = array_map(static fn($r) => [
                'code'     => (string) $r['code'],
                'name'     => (string) $r['name'],
                'price'    => (float)  $r['price'],
                'rate'     => (float)  $r['rate'],
                'cap'      => (float)  $r['cap'],
                'tradeEok' => (float)  $r['tradeEok'],
                'kind'     => (string) $r['kind'],
            ], $st->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 최근조회 목록 (etf_stock.php와 공유: etf_recent_view_stocks) ──
        case 'recent': {
            $repo = new StockRepository($pdo);
            echo json_encode($repo->getRecentStocks(8), JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── 최근조회 기록 저장 (검색/칩으로 종목 열 때 호출) ──
        case 'saverecent': {
            $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
            $name = trim((string)($_GET['name'] ?? ''));
            if ($code !== '' && $name !== '') {
                (new StockRepository($pdo))->saveRecentStock($code, $name);
            }
            echo json_encode(['ok' => true]);
            return;
        }

        // ── 시세 갱신 시각 (data_update_status 테이블에서 직접 조회) ──
        case 'meta': {
            $updated = '';
            try {
                $st = $pdo->prepare("SELECT update_time FROM data_update_status WHERE data_key = :k");
                $st->execute([':k' => 'all_data_from_naver']);
                $updated = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable $e) { $updated = ''; }
            echo json_encode(['updated' => $updated], JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
    }
}
?>
