<?php
/**
 * market/api.php — 모닝브리핑 리포트용 실시간(on-demand) 데이터 엔드포인트
 *
 *   action=invtrend&market=kospi|kosdaq&days=120
 *     → {"series":[{date,individual,foreign,institution}, ...]}  (오래된→최신, 억원)
 *
 * 크롤·스냅샷에 저장하지 않고 호출 시점에 네이버에서 가져온다.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
if (($_GET['key'] ?? '') !== MKT_KEY) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'invtrend':
        $sosok  = ($_GET['market'] ?? 'kospi') === 'kosdaq' ? '02' : '01';
        $days   = (int) ($_GET['days'] ?? 120);
        $series = mkt_naver_investor_series($sosok, $days);
        echo json_encode(['series' => $series], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
}
