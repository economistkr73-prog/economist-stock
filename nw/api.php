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

    // 잘못 올린 계약서 사진 삭제
    if ($action === 'deleteImage') {
        $nw->deleteContractImage((int)($_POST['id'] ?? 0));
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
            'parking_fee'     => $_POST['parking_fee'] ?? '',
            'water_fee'       => $_POST['water_fee'] ?? '',
            'electric_fee'    => $_POST['electric_fee'] ?? '',
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

    // 공과금(수도·전기) 월별 부과액 저장 (호실+월 upsert)
    if ($action === 'saveCharge') {
        $nw->upsertUtilityCharge(
            (int)($_POST['unit_id'] ?? 0),
            (string)($_POST['bill_ym'] ?? ''),
            (int)preg_replace('/[^0-9]/', '', (string)($_POST['water_fee'] ?? '0')),
            (int)preg_replace('/[^0-9]/', '', (string)($_POST['electric_fee'] ?? '0'))
        );
        echo json_encode(['ok' => true]);
        return;
    }

    // 청구월 요금 상수 저장
    if ($action === 'saveConfig') {
        $bid = (int)($_POST['building_id'] ?? 0);
        $ym  = (string)($_POST['bill_ym'] ?? '');
        if (!$bid || !preg_match('/^\d{4}-\d{2}$/', $ym)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => '건물/청구월 오류']); return; }
        $g = function ($k, $def) { return is_numeric($_POST[$k] ?? null) ? (float)$_POST[$k] : $def; };
        $nw->saveUtilityConfig($bid, $ym, [
            'water_base' => $g('water_base', 2160),
            'water_unit' => $g('water_unit', 930),
            'elec_tv'    => $g('elec_tv', 2500),
            'elec_base'  => $g('elec_base', 910),
            'elec_unit'  => $g('elec_unit', 93.3),
        ]);
        echo json_encode(['ok' => true]);
        return;
    }

    // 검침값 + 계산요금 일괄 저장
    if ($action === 'bulkReadings') {
        $bid  = (int)($_POST['building_id'] ?? 0);
        $ym   = (string)($_POST['bill_ym'] ?? '');
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!$bid || !preg_match('/^\d{4}-\d{2}$/', $ym) || !is_array($rows)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => '데이터 오류']); return; }
        $n = 0;
        foreach ($rows as $r) {
            $uid = (int)($r['unit_id'] ?? 0);
            if (!$uid) continue;
            $nw->saveUtilityReading($uid, $ym, [
                'elec_prev'    => (int)($r['elec_prev'] ?? 0),
                'elec_cur'     => (int)($r['elec_cur'] ?? 0),
                'cold_prev'    => (int)($r['cold_prev'] ?? 0),
                'cold_cur'     => (int)($r['cold_cur'] ?? 0),
                'hot_prev'     => (int)($r['hot_prev'] ?? 0),
                'hot_cur'      => (int)($r['hot_cur'] ?? 0),
                'read_prev_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['read_prev_date'] ?? '')) ? $r['read_prev_date'] : null,
                'read_cur_date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['read_cur_date'] ?? '')) ? $r['read_cur_date'] : null,
                'bill_weight'  => max(1, (int)($r['bill_weight'] ?? 2)),
                'water_fee'    => (int)($r['water_fee'] ?? 0),
                'electric_fee' => (int)($r['electric_fee'] ?? 0),
            ]);
            $n++;
        }
        echo json_encode(['ok' => true, 'count' => $n]);
        return;
    }

    // 공과금 월별 일괄 저장 (건물 전 호실, 청구월+주기)
    if ($action === 'bulkCharge') {
        $billYm = (string)($_POST['bill_ym'] ?? '');
        $span   = max(1, min(3, (int)($_POST['span_months'] ?? 1)));
        $rows   = json_decode($_POST['rows'] ?? '[]', true);
        if (!preg_match('/^\d{4}-\d{2}$/', $billYm) || !is_array($rows)) {
            http_response_code(400); echo json_encode(['ok' => false, 'msg' => '청구월/데이터가 올바르지 않습니다.']); return;
        }
        $n = 0;
        foreach ($rows as $r) {
            $uid = (int)($r['unit_id'] ?? 0);
            if (!$uid) continue;
            $nw->upsertUtilityCharge(
                $uid, $billYm,
                (int)preg_replace('/[^0-9]/', '', (string)($r['water'] ?? '0')),
                (int)preg_replace('/[^0-9]/', '', (string)($r['electric'] ?? '0')),
                $span
            );
            $n++;
        }
        echo json_encode(['ok' => true, 'count' => $n]);
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
