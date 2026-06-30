<?php
// waste_api.php — 공제조합 회원관리 API (module=waste)
//   action=bootstrap  지도 페이지 초기 로딩: 전체 업체 + 통계 + 필터 옵션 (1회 호출)
//   action=search     필터 조건으로 업체 목록 (서버 필터)
//   action=stats      대시보드 통계만
//
// 부트스트랩은 place_api.php 패턴과 동일 (ob_start → 인증 → ob_clean → JSON).
ob_start();
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? 'waste';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($module !== 'waste') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => "unknown module: {$module}"]);
        exit;
    }
    $wc = new WasteCompany($pdo);
    $wc->ensureTable();

    switch ($action) {
        case 'bootstrap': {
            echo json_encode([
                'ok'          => true,
                'rows'        => $wc->search(['only_geo' => 0]),
                'stats'       => $wc->stats(),
                'sido'        => $wc->sidoList(),
                'waste_types' => $wc->wasteTypeList(),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'search': {
            $f = [
                'sido'       => trim((string)($_GET['sido'] ?? '')),
                'sigungu'    => trim((string)($_GET['sigungu'] ?? '')),
                'waste_type' => trim((string)($_GET['waste_type'] ?? '')),
                'only_geo'   => (int)($_GET['only_geo'] ?? 0),
            ];
            foreach (['is_member', 'is_coop', 'active', 'target'] as $k) {
                if (isset($_GET[$k]) && $_GET[$k] !== '') $f[$k] = (int)$_GET[$k];
            }
            echo json_encode(['ok' => true, 'rows' => $wc->search($f)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'stats': {
            echo json_encode(['ok' => true, 'stats' => $wc->stats()], JSON_UNESCAPED_UNICODE);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
