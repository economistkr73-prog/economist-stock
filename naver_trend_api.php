<?php
// naver_trend_api.php — 네이버 맛집 추이 데이터 API (module=naver)
//   action=periods   수집 회차 목록            → {periods:["2026-07","2026-06",...]}
//   action=list      한 회차 + 전월대비 델타   → {period,total,rows:[{naver_id,name,region,score,review,...,d_review,...}]}
//                       params: period, region, min_review, sort, dir, limit, offset
//   action=series    한 맛집 시계열            → {nid,series:[{period,score,review,visitor,blog,save}]}  (?nid=)
//
// 규칙: 모든 응답 JSON. 빈/실패는 빈 구조 + 200, 진짜 에러만 {error}.
// 부트스트랩은 stock_analysis_api.php / place_api.php 패턴과 동일 (ob_start → 인증 → ob_clean → JSON).
ob_start();
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $col = new NaverPlaceCollector($pdo);
    $col->ensureTables();

    switch ($action) {
        case 'periods': {
            echo json_encode(['periods' => $col->periods()], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'list': {
            $periods = $col->periods();
            $period  = (string)($_GET['period'] ?? '');
            if ($period === '' && $periods) $period = $periods[0];   // 기본 = 최신 회차
            $r = $col->trendList([
                'period'     => $period,
                'region'     => trim((string)($_GET['region'] ?? '')),
                'min_review' => (int)($_GET['min_review'] ?? 0),
                'sort'       => (string)($_GET['sort'] ?? 'd_review'),
                'dir'        => (string)($_GET['dir'] ?? 'desc'),
                'limit'      => (int)($_GET['limit'] ?? 100),
                'offset'     => (int)($_GET['offset'] ?? 0),
            ]);
            $r['periods'] = $periods;
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'series': {
            $nid = trim((string)($_GET['nid'] ?? ''));
            echo json_encode(['nid' => $nid, 'series' => $nid !== '' ? $col->series($nid) : []], JSON_UNESCAPED_UNICODE);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
