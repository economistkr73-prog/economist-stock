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

    if ($action === 'add') {
        $d = $_POST;
        $d['room_id'] = (int)($_POST['room_id'] ?? 0);
        $id = $nw->addContract($d);
        echo json_encode(['ok' => true, 'id' => $id]);
        return;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nw->updateContract($id, $_POST);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'renew') {
        $oldId = (int)($_POST['renew_from'] ?? 0);
        $newId = $nw->renewContract($oldId, $_POST);
        echo json_encode(['ok' => true, 'id' => $newId]);
        return;
    }

    if ($action === 'impliedRenewal') {
        $nw->markImpliedRenewal((int)($_POST['id'] ?? 0));
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'end') {
        $nw->endContract((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? 'expired'));
        echo json_encode(['ok' => true]);
        return;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
}

function api_nw_payment(string $action, PDO $pdo): void {
    $nw = new Nw($pdo);
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
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
}
?>
