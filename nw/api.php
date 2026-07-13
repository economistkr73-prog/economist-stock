<?php
/**
 * nw/api.php — 원룸관리 AJAX 엔드포인트 (module + action)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($module) {
        case 'building': api_nw_building($action, $pdo); break;
        case 'unit':      api_nw_unit($action, $pdo);     break;
        case 'contract':  api_nw_contract($action, $pdo); break;
        case 'payment':   api_nw_payment($action, $pdo);  break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown module: {$module}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}

function api_nw_building(string $action, PDO $pdo): void {
    $nw = new Nw($pdo);
    if ($action === 'add') {
        $id = $nw->addBuilding($_POST);
        echo json_encode(['ok' => true, 'id' => $id]);
        return;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
}

function api_nw_unit(string $action, PDO $pdo): void {
    $nw = new Nw($pdo);
    if ($action === 'add') {
        $id = $nw->addUnit([
            'building_id' => (int)($_POST['building_id'] ?? 0),
            'room_no'     => $_POST['room_no'] ?? '',
            'room_size'   => $_POST['room_size'] ?? '',
            'bank_loan'   => $_POST['bank_loan'] ?? '',
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
        return;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
}

function api_nw_contract(string $action, PDO $pdo): void {
    $nw = new Nw($pdo);

    // 계약서 사진(멀티파트) → Claude Vision 판독 + 원본 이미지 DB 저장
    if ($action === 'scanImage') {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            echo json_encode(['ok' => false, 'msg' => '이미지 파일이 없습니다.']);
            return;
        }
        $nw->ensureTable(); // nw_contract_image 존재 보장(멱등)
        $bytes = file_get_contents($_FILES['file']['tmp_name']);
        $mime  = $_FILES['file']['type'] ?: 'image/jpeg';
        $fname = $_FILES['file']['name'] ?? null;
        $unitId    = (int)($_POST['room_id'] ?? 0) ?: null;
        $contractId = (int)($_POST['contract_id'] ?? 0) ?: null;

        // 원본을 먼저 저장(판독 실패해도 사진은 보관)
        $imageId = $nw->saveContractImage($contractId, $unitId, $mime, $bytes, $fname);

        // Claude Vision 판독 (env/anthropic.inc 필요)
        $incF = $_SERVER['DOCUMENT_ROOT'] . '/env/anthropic.inc';
        if (is_file($incF)) require_once $incF;
        $fields = $nw->extractContractFromImage($bytes, $mime);
        if ($fields === null) {
            echo json_encode(['ok' => true, 'image_id' => $imageId, 'fields' => null,
                              'msg' => 'AI 판독에 실패했습니다. 이미지는 저장되었으니 직접 입력해 주세요.']);
            return;
        }
        echo json_encode(['ok' => true, 'image_id' => $imageId, 'fields' => $fields]);
        return;
    }

    if ($action === 'add') {
        $d = $_POST;
        $d['room_id'] = (int)($_POST['room_id'] ?? 0);
        $id = $nw->addContract($d);
        if ($imgId = (int)($_POST['image_id'] ?? 0)) $nw->linkContractImage($imgId, $id);
        echo json_encode(['ok' => true, 'id' => $id]);
        return;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nw->updateContract($id, $_POST);
        if ($imgId = (int)($_POST['image_id'] ?? 0)) $nw->linkContractImage($imgId, $id);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'renew') {
        $oldId = (int)($_POST['renew_from'] ?? 0);
        $newId = $nw->renewContract($oldId, $_POST);
        if ($imgId = (int)($_POST['image_id'] ?? 0)) $nw->linkContractImage($imgId, $newId);
        echo json_encode(['ok' => true, 'id' => $newId]);
        return;
    }

    if ($action === 'impliedRenewal') {
        $nw->markImpliedRenewal((int)($_POST['id'] ?? 0));
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'end') {
        $nw->endContract((int)($_POST['id'] ?? 0), $_POST['move_out_date'] ?? null);
        echo json_encode(['ok' => true]);
        return;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
}

function api_nw_payment(string $action, PDO $pdo): void {
    $nw = new Nw($pdo);
    $nw->ensureTable(); // bill_ym 등 스키마 보장(멱등)
    if ($action === 'add') {
        $id = $nw->addPayment([
            'contract_id'     => (int)($_POST['contract_id'] ?? 0),
            'pay_date'        => $_POST['pay_date'] ?? '',
            'rent_fee'        => $_POST['rent_fee'] ?? '',
            'maintenance_fee' => $_POST['maintenance_fee'] ?? '',
            'memo'            => $_POST['memo'] ?? '',
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
        return;
    }

    // 은행 거래내역(.xls) 업로드 → 파싱 + 건물 활성계약 자동매칭 (검토용 데이터 반환)
    if ($action === 'importParse') {
        $buildingId = (int)($_GET['building_id'] ?? $_POST['building_id'] ?? 0);
        if (!$buildingId) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => '건물이 지정되지 않았습니다.']); return; }
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            http_response_code(400); echo json_encode(['ok' => false, 'msg' => '업로드된 파일이 없습니다.']); return;
        }
        $bytes  = file_get_contents($_FILES['file']['tmp_name']);
        $parsed = (new BankXls())->parse($bytes);
        $rows   = $parsed['rows'];
        if (!$rows) { echo json_encode(['ok' => true, 'rows' => [], 'candidates' => [], 'format' => $parsed['format'], 'msg' => '거래 행을 찾지 못했습니다.']); return; }

        $contracts = $nw->listAllContractsByBuilding($buildingId); // 과거(공실전 세입자) 계약도 매칭 후보로
        $dates = array_column($rows, 'date');
        $existKeys = $nw->existingPaymentKeys(
            array_column($contracts, 'id'),
            min($dates), max($dates)
        );
        $match = $nw->matchBankRows($contracts, $rows, $existKeys, min($dates), max($dates));

        echo json_encode([
            'ok' => true,
            'format' => $parsed['format'],
            'file'   => $_FILES['file']['name'] ?? '',
            'rows'   => $match['rows'],
            'candidates' => $match['candidates'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'update') {
        $nw->updatePayment((int)($_POST['id'] ?? 0), $_POST);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'delete') {
        $nw->deletePayment((int)($_POST['id'] ?? 0));
        echo json_encode(['ok' => true]);
        return;
    }

    // 검토 확정된 납부 일괄등록
    if ($action === 'bulkImport') {
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => '잘못된 데이터입니다.']); return; }
        $res = $nw->bulkAddPayments($items);
        echo json_encode(['ok' => true] + $res);
        return;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
}
?>
