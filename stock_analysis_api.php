<?php
// stock_analysis_api.php — 상승종목 분석 대시보드 데이터 API (module=stock)
//   action=top30   상승률 상위 30  → [{code,name,price,rate,tradeEok,q:퀀트배지|null}, ...]
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
        case 'ind':   api_ind($action, $pdo);   break;   // 사용자 지표 (style/dailychart.js 소비)
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
            // 퀀트 배지(q) — krx_amt/krx_surge 원장 판정. 원장 미구축 환경이면 배지 없이 목록만.
            try { quant_augment($pdo, $out); } catch (Throwable $e) { /* 배지는 부가정보 */ }
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
            // 허용: 160/240/480/1000 영업일. 1000(≈4년)은 주봉(dailychart.js setTf) 뷰용
            if (!in_array($want, [160, 240, 480, 1000], true)) $want = $want >= 240 ? 240 : 160;
            $cal = (int)ceil($want * 1.55) + 10;     // 영업일 확보용 달력일수(주말·휴일 버퍼 포함)

            $api = new NaverFinanceAPI();
            $res = $api->getDailyOhlc($code, $cal);
            if (isset($res['error']) || !isset($res['success'])) { echo json_encode([]); return; }

            // 최근 N영업일만 (오름차순 유지)
            $rows = $res['success'];
            if (count($rows) > $want) $rows = array_slice($rows, -$want);
            $rows = array_values($rows);

            /* 실제 거래대금(KRX) 병합 — 있는 날만 'a' 로 실어 보낸다.
             * 네이버 일봉엔 거래대금이 없어 지표 엔진이 「종가×거래량」으로 근사하는데,
             * 그 오차(중앙 0.99%)가 「전고 거래대금 돌파」 판정을 뒤집을 수 있다(실측 0.79%).
             * 값이 없는 날은 키를 넣지 않는다 — 엔진이 근사로 폴백하고, 화면은 그 사실을 안다. */
            try {
                $ka = new KrxAmt($pdo);
                $ser = $ka->series($code, $rows[0]['t'] ?? '', $rows[count($rows) - 1]['t'] ?? '');
                if ($ser) {
                    foreach ($rows as &$r) {
                        if (isset($ser[$r['t']])) $r['a'] = $ser[$r['t']];
                    }
                    unset($r);
                }
            } catch (Throwable $e) { /* 테이블 미생성 등 — 근사로 계속 */ }

            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
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

// ==========================================================
// 퀀트 배지 — top30 목록에 "이 종목을 퀀트 잣대로 읽으면" 을 얹는다 (관찰 화면용, 2026-08-02)
//
//   q = null(ETF·이력부족) 또는 {
//     t/cls/tip : 오늘 거래대금이 직전 120거래일 최고를 넘었을 때만 — 유형 판정
//                 (임계·어휘 = stock/index.php pf_surge_badge 와 동일:
//                  추격주의 등락≥20% → 폭발형 20평비≥20 → 매집형 20평비≤5∧등락0~10% → 중립.
//                  나쁜 쪽 우선. 장중 거래대금은 하한이라 매집형→폭발형으로 마감에 바뀔 수 있어 '잠정' 명시)
//     hot       : 신호 전 20일 +80% 또는 40일 +100% 급등 (KrxAmt::MOM_HOT20/40)
//     bx        : 최근 최고 거래대금 신호(krx_surge)의 박스 상태 (boxStatusMany — 어닝 탭과 동일 재사용)
//   }
//   ★역사: 이 자리에 있던 흰칩('60봉 신고가+거래량 2배'·정적 승률 78/82/90%)은 2026-08-02 폐기.
//     정적 추정이 실측(칼리브레이션)과 어긋났고, 전략 자체가 퀀트 백테스트에서 기각된 계열이다.
// ==========================================================
function quant_augment(PDO $pdo, array &$rows): void
{
    $codes = [];
    foreach ($rows as $r) if ($r['kind'] === 'stock') $codes[] = $r['code'];
    if (!$codes) return;
    $in = implode(',', array_fill(0, count($codes), '?'));

    // ① 과거 원장(오늘 제외 — 오늘 잠정행 src='n' 이 15:50 이후 있을 수 있다) 최근 120거래일
    $st = $pdo->prepare("SELECT code, d, c, amt FROM krx_amt
                          WHERE code IN ($in) AND d < CURDATE()
                            AND d >= DATE_SUB(CURDATE(), INTERVAL 200 DAY)
                            AND amt > 0 AND c > 0
                          ORDER BY code, d");
    $st->execute($codes);
    $hist = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $hist[$r['code']][] = $r;

    // ② 최근 최고 거래대금 신호의 박스 상태 (krx_surge 미구축이면 조용히 생략)
    $bx = [];
    try {
        $sg = $pdo->prepare("SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code");
        $sg->execute($codes);
        $sigs = [];
        foreach ($sg->fetchAll(PDO::FETCH_ASSOC) as $r) $sigs[] = ['code' => $r['code'], 'd' => $r['d']];
        if ($sigs) {
            $bxAll = (new KrxAmt($pdo))->boxStatusMany($sigs);
            foreach ($sigs as $s) {
                $b = $bxAll[$s['code'] . '|' . $s['d']] ?? null;
                if ($b) $bx[$s['code']] = ['st' => $b['st'], 'txt' => $b['txt'],
                    'tip' => '최고 거래대금 신호일 ' . $s['d'] . ' — ' . $b['tip']];
            }
        }
    } catch (Throwable $e) { /* 박스 없이 계속 */ }

    foreach ($rows as &$r) {
        if ($r['kind'] !== 'stock') { $r['q'] = null; continue; }
        $h = $hist[$r['code']] ?? [];
        $n = count($h);
        $q = ['t' => '', 'cls' => '', 'tip' => '', 'hot' => 0, 'mom' => [], 'bx' => $bx[$r['code']] ?? null];

        /* 20·40거래일 모멘텀 — 오늘 현재가 vs 20/40거래일 전 종가 (퀀트 momMany 와 같은 임계).
         * ★ 창별·방향별로 나눠 보낸다(2026-08-02) — 화면은 「20일 +112%」처럼 기간+부호%로 그린다.
         *   급등은 실측 근거가 있어 경고색, 급락은 근거가 없어 정보색(로그 대칭 임계일 뿐). */
        $price = (float)$r['price'];
        if ($price > 0 && $n >= 20) {
            $c20 = (float)$h[$n - 20]['c'];
            $m20 = $c20 > 0 ? $price / $c20 - 1 : null;
            $m40 = null;
            if ($n >= 40) { $c40 = (float)$h[$n - 40]['c']; if ($c40 > 0) $m40 = $price / $c40 - 1; }
            foreach ([[20, $m20], [40, $m40]] as [$w, $m]) {
                if ($m === null) continue;
                $hi = ($w === 20) ? KrxAmt::MOM_HOT20  : KrxAmt::MOM_HOT40;
                $lo = ($w === 20) ? KrxAmt::MOM_COLD20 : KrxAmt::MOM_COLD40;
                if ($m >= $hi)      $q['mom'][] = ['w' => $w, 'v' => round($m * 100), 'hot' => 1];
                elseif ($m <= $lo)  $q['mom'][] = ['w' => $w, 'v' => round($m * 100), 'hot' => 0];
            }
            if ($q['mom']) $q['hot'] = 1;   // 옛 소비자 호환 (합집합)
        }

        // 유형 — 오늘 거래대금이 직전 120거래일 최고를 넘었을 때만 (그 외엔 배지 없음)
        $todayAmt = (float)$r['tradeEok'] * 1e8;   // stock_vol_cap = 억원
        if ($n >= 40 && $todayAmt > 0) {
            $win = array_slice($h, -120);
            $maxAmt = 0.0;
            foreach ($win as $b) if ((float)$b['amt'] > $maxAmt) $maxAmt = (float)$b['amt'];
            if ($maxAmt > 0 && $todayAmt >= $maxAmt) {
                $a20 = array_slice($h, -20);
                $sum = 0.0;
                foreach ($a20 as $b) $sum += (float)$b['amt'];
                $mul = $sum > 0 ? $todayAmt / ($sum / count($a20)) : null;
                $chg = (float)$r['rate'] / 100;    // stock_rate 는 % 단위
                if ($chg >= 0.20)                                { $cls = 'chase'; $t = '추격주의';
                    $why = '등락 +20% 이상 — 실측 +20일 초과수익 중앙 -7.23% · 승률 34.6%'; }
                elseif ($mul !== null && $mul >= 20)             { $cls = 'exp';   $t = '폭발형';
                    $why = '20일 평균의 20배 이상 폭발 — 실측 중앙 -4.24% · 승률 36.7%'; }
                elseif ($mul !== null && $mul <= 5 && $chg >= 0 && $chg < 0.10) { $cls = 'acc'; $t = '🟢매집형';
                    $why = '20일 평균의 5배 이하 + 등락 0~10%로 조용히 차오른 최고 거래대금 — 실측 중앙 +1.74% · 승률 55.2%'; }
                else                                             { $cls = '';      $t = ''; $why = ''; }
                // 중립은 배지를 그리지 않는다(2026-08-02 사용자 지시 · 퀀트 목록과 같은 규칙)
                if ($t !== '') {
                    $q['t'] = $t; $q['cls'] = $cls;
                    $q['tip'] = '오늘 거래대금 ' . number_format($todayAmt / 1e8) . '억 = 직전 120거래일 최고('
                        . number_format($maxAmt / 1e8) . '억) 이상'
                        . ($mul !== null ? ' · 20일 평균의 ' . round($mul, 1) . '배' : '')
                        . ' — ' . $why . ' (장중엔 잠정 · 마감 후 확정)';
                }
            }
        }
        $r['q'] = ($q['t'] === '' && !$q['hot'] && $q['bx'] === null) ? null : $q;
    }
    unset($r);
}

// ==========================================================
// ind 모듈 — 사용자 지표 CRUD (계산·표시는 style/dailychart.js 가 담당)
//   action=list             → [{id,name,draw,expr,vars,color,note}, ...]
//   action=save (POST)      → {ok:1,id}   (id 있으면 수정)
//   action=del  (POST id)   → {ok:1}
// ==========================================================
function api_ind(string $action, PDO $pdo): void
{
    $ci = new ChartIndicator($pdo);
    switch ($action) {

        case 'list':
            echo json_encode($ci->list(), JSON_UNESCAPED_UNICODE);
            return;

        case 'save': {
            $id = $ci->save($_POST);
            echo json_encode(['ok' => 1, 'id' => $id]);
            return;
        }

        case 'del': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id 누락']); return; }
            $ci->delete($id);
            echo json_encode(['ok' => 1]);
            return;
        }

        // ── 저장된 차트 (기간·시간축 + 축별 지표 세트) + 화면별 마지막 적용 차트 ──
        case 'preset': {          // 목록 + 화면별 선택 상태를 한 번에
            echo json_encode(['presets' => $ci->presets(), 'prefs' => $ci->prefs()], JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'preset_save': {     // id 있으면 그 차트의 해당 축 세트만 갱신 (view 는 매번 갱신)
            $id = $ci->presetSave($_POST);
            echo json_encode(['ok' => 1, 'id' => $id]);
            return;
        }
        case 'preset_del': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id 누락']); return; }
            $ci->presetDelete($id);
            echo json_encode(['ok' => 1]);
            return;
        }
        case 'pref_save': {       // 이 화면에서 마지막으로 쓴 틀 기억
            $ci->prefSave((string)($_POST['chart_key'] ?? ''), (int)($_POST['preset_id'] ?? 0));
            echo json_encode(['ok' => 1]);
            return;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
    }
}
?>
