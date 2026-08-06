<?php
// stock_analysis_api.php — 시세·차트 공용 데이터 API (module=stock)
//   action=top30   상승률/시총 상위 30 → [{code,name,price,rate,tradeEok,q:퀀트배지|null}, ...]
//   action=news    종목 뉴스       → [{title,url,date}, ...]   (?code=005930)
//   action=daily   일봉            → [{t:"YYYY-MM-DD",o,h,l,c,v}, ...]  (?code=&days=)
//   action=minute  당일 1분봉      → [{t:"YYYY-MM-DD HH:MM",o,h,l,c,v}, ...] (?code=)
//
// ★소비자는 단타(/stock?mode=short — 목록·뉴스·일봉)와 차트 공용모듈(style/dailychart.js —
//   daily·minute)이다. 원래 상승종목분석 대시보드용으로 낸 파일인데, 그 화면은 2026-08-05 에
//   삭제됐고 남은 액션은 전부 다른 화면이 쓰고 있다(그래서 파일은 남는다).
//
// 규칙:
//   - 모든 응답 Content-Type: application/json; charset=utf-8
//   - 빈/실패는 빈 배열 [] + HTTP 200, 진짜 에러만 {error:"..."}
// 부트스트랩은 place_api.php / schedule_api.php 패턴과 동일 (ob_start → 인증 → ob_clean → JSON).
ob_start(); // included 파일의 stray output 방지
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_once __DIR__ . "/stock/lib/quant.php";   // 목록 퀀트 배지 판정 단일본 (단타 목록과 공유)
require_login();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? 'stock';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($module) {
        case 'stock': api_stock($action, $pdo); break;
        case 'ind':   api_ind($action, $pdo);   break;   // 사용자 지표 (style/dailychart.js 소비)
        case 'sue':   api_sue($action, $pdo);   break;   // SUE 공시 마커 (모든 차트 공용 레이어)
        case 'band':  api_band($action, $pdo);  break;   // PER·PBR 밴드 (재무분석 종목상세)
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

            /* ★수집 대상(단타 풀 ∪ 보유 · 최대 100종목)은 <b>키움</b>으로 받는다 (2026-08-06 사용자 지시).
             *
             * 왜 — 같은 종목이 화면마다 다른 값을 말했다(실측: 1분봉 230,000 · 일봉 230,250 ·
             * 3분봉 246,000). 소스가 셋(네이버 분봉 · 네이버 fchart 일봉 · 원장)이었기 때문이다.
             * 현재가·분봉을 키움으로 옮겼으니 일봉도 같이 옮겨야 <b>한 화면이 한 값</b>을 말한다.
             *
             * ★과거 차트는 안 바뀐다 — ka10081 실측에서 네이버와 과거 7거래일 종가·거래량이
             *   <b>전부 일치</b>했다(어긋난 건 장중인 오늘 하나뿐, 그것도 받은 시점 차이).
             * ★대상이 아닌 종목은 <b>그대로 네이버</b>다 — 전종목을 키움에 걸면 1 req/s 라
             *   종목을 훑을 때마다 1초씩 밀리고, 키움이 죽으면 전 화면 차트가 함께 죽는다.
             * ★실패하면 네이버로 떨어진다(아래 if 가 그 폴백이다). */
            $rows = [];
            try {
                $dtc = new Dt($pdo);
                if (in_array($code, $dtc->targetCodes(), true)) {
                    $kw = new Kiwoom($pdo);
                    if ($kw->hasKey()) $rows = $kw->daily($code, $want);
                }
            } catch (Throwable $e) { $rows = []; }

            if (!$rows) {
                $api = new NaverFinanceAPI();
                $res = $api->getDailyOhlc($code, $cal);
                if (isset($res['error']) || !isset($res['success'])) { echo json_encode([]); return; }
                $rows = $res['success'];
            }

            // 최근 N영업일만 (오름차순 유지)
            if (count($rows) > $want) $rows = array_slice($rows, -$want);
            $rows = array_values($rows);
            if (!$rows) { echo json_encode([]); return; }

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

        /* ★종목 검색·최근조회·시세시각(search/recent/saverecent/meta)은 2026-08-05 에 지웠다 —
         *   상승종목분석 대시보드 전용이었고, 그 화면과 함께 소비자가 사라졌다.
         *   단타는 검색을 /stock/api.php?module=stock&action=search 로, 최근조회를
         *   StockRepository 로 직접 읽는다(같은 표 etf_recent_view_stocks). */

        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
    }
}

// ==========================================================
// 퀀트 배지 — top30 목록에 "이 종목을 퀀트 잣대로 읽으면" 을 얹는다 (관찰 화면용, 2026-08-02)
//
//   ★판정 본체는 2026-08-05 에 `stock/lib/quant.php` 의 quant_badge_many() 로 옮겼다.
//     화면이 여럿이라 두 곳에 두면 임계를 고칠 때 한 화면만 조용히 옛말을 하게 되기
//     때문이다. 여기 남은 것은 <b>top30 의 행 모양(kind/price/rate/tradeEok)을
//     그 함수의 입력으로 옮기는 어댑터</b>뿐이다.
//     그리는 쪽(어휘·색)의 단일본은 `style/quantbadge.js`.
// ==========================================================
function quant_augment(PDO $pdo, array &$rows): void
{
    // ETF 는 원장(krx_amt) 대상이 아니라 배지가 없다 — 아예 넣지 않는다(= q null).
    $items = [];
    foreach ($rows as $r) {
        if ($r['kind'] !== 'stock') continue;
        $items[$r['code']] = [
            'price'  => (float)$r['price'],
            'rate'   => (float)$r['rate'],      // %
            'amtEok' => (float)$r['tradeEok'],  // stock_vol_cap = 억원
        ];
    }
    $q = quant_badge_many($pdo, $items);
    foreach ($rows as &$r) $r['q'] = $q[$r['code']] ?? null;
    unset($r);
}

// ==========================================================
// ind 모듈 — 사용자 지표 CRUD (계산·표시는 style/dailychart.js 가 담당)
//   action=list             → [{id,name,draw,expr,vars,color,note}, ...]
//   action=save (POST)      → {ok:1,id}   (id 있으면 수정)
//   action=del  (POST id)   → {ok:1}
//   action=preset|preset_save|preset_del|pref_save  → 차트틀(지표 세트)
//   action=feat             → {catalog, values, over}  화면별 기능 구성 (원본 = ChartFeat)
//   action=feat_save (POST screen, vals|reset) → {ok:1, values}
//   action=view_save (POST chart_key, view)    → {ok:1, view}  화면이 기억하는 보기 값(차트 높이 …)
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

        // ── 화면별 기능 구성 (도구모음·레이어) — 원본 카탈로그는 ChartFeat ──
        case 'feat': {            // 카탈로그 + 화면별 최종값(기본값에 저장 차분을 얹은 것)
            $over = $ci->featuresAll();
            $vals = [];
            foreach (array_keys(ChartFeat::SCREENS) as $sk) {
                $vals[$sk] = ChartFeat::merge($sk, $over[$sk] ?? null);
            }
            echo json_encode(['catalog' => ChartFeat::catalog(), 'values' => $vals,
                              'over' => $over], JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'view_save': {       // 화면이 기억하는 보기 값 (차트 높이 …)
            $v = $ci->viewSave((string)($_POST['chart_key'] ?? ''), $_POST['view'] ?? '{}');
            echo json_encode(['ok' => 1, 'view' => $v ?: new stdClass()], JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'feat_save': {
            $screen = (string)($_POST['screen'] ?? '');
            $reset  = !empty($_POST['reset']);
            $vals   = $_POST['vals'] ?? '{}';
            if (is_string($vals)) $vals = json_decode($vals, true);
            if (!is_array($vals)) $vals = [];
            $merged = $ci->featuresSave($screen, $vals, $reset);
            echo json_encode(['ok' => 1, 'values' => $merged], JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
    }
}

// ==========================================================
// sue 모듈 — 차트에 얹을 SUE 공시 마커 (모든 차트가 쓰는 공용 레이어)
//   action=marks (?code=005930) → {marks:[{d:접수일, q:'25.4Q', sue:7.7}, …], hit, shock}
//
// 계산은 stock/lib/sue.php 단일본(pf_sue_marks). 여기는 그것을 화면에 열어 주는 창구다.
// 「공시 다음 거래일」 스냅은 봉을 들고 있는 클라이언트(style/dailychart.js)가 한다.
// ==========================================================
function api_sue(string $action, PDO $pdo): void
{
    if ($action !== 'marks') {
        http_response_code(400);
        echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
        return;
    }
    $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
    if (strlen($code) !== 6) { echo json_encode(['marks' => []]); return; }   // 빈 결과 = 200 (규칙)

    require_once __DIR__ . '/stock/lib/sue.php';
    try {
        $marks = pf_sue_marks($pdo, $code);
    } catch (Throwable $e) {
        $marks = [];      // 재무·공시 원장이 아직 없는 종목이면 마커 없이 그린다 (조용히 생략)
    }
    echo json_encode(['marks' => $marks, 'hit' => Thr::SUE_HIT, 'shock' => Thr::SUE_SHOCK],
                     JSON_UNESCAPED_UNICODE);
}

// ==========================================================
// module=band — PER·PBR 밴드 차트 (재무분석 종목상세)
// 계산은 stock/lib/band.php 단일본(pf_band_series). 여기는 창구일 뿐이다.
// 배수 분위수·TTM·공시일 정렬을 JS 로 옮기면 같은 규칙이 두 군데 살게 된다.
// ==========================================================
function api_band(string $action, PDO $pdo): void
{
    if ($action !== 'series') {
        http_response_code(400);
        echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
        return;
    }
    $code  = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
    $years = (int)($_GET['years'] ?? 5);
    if (strlen($code) !== 6) { echo json_encode(['ok' => false, 'px' => []]); return; }

    require_once __DIR__ . '/stock/lib/band.php';
    try {
        $b = pf_band_series($pdo, $code, $years);
    } catch (Throwable $e) {
        $b = ['ok' => false, 'px' => [], 'note' => ['밴드를 만들지 못했습니다.']];
    }
    echo json_encode($b, JSON_UNESCAPED_UNICODE);
}
?>
