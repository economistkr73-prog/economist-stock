<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . "/../env/cnt.inc";
require_once __DIR__ . "/../env/auth_fnc.php";
require_once __DIR__ . "/../env/e.fnc";
require_once __DIR__ . "/../classes/data_processors.class";

define('CUR_PHP', basename($_SERVER['PHP_SELF']));

$mode = $_GET['mode'] ?? 'display';

// AJAX/팝업 전용 모드는 header.php/로그인체크 불필요
$no_header_modes = ['rtfn', 'ga', 'etf_update'];
if (!in_array($mode, $no_header_modes)) {
    require_login();
    require_once __DIR__ . "/../env/header.php";
}

// ==========================================================
// 라우팅 테이블
// ==========================================================
$routes = [
    'rtfn'       => 'mode_rtfn',
    'ga'         => 'mode_ga',
    'etf_update' => 'mode_etf_update',
    'display'    => 'mode_display',
];

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo);
} else {
    exit;
}

// ==========================================================
// mode=rtfn  네이버 실시간 시세 갱신
// ==========================================================
function mode_rtfn($pdo) {
    try {
        $api        = new NaverFinanceAPI();
        $is_success = $api->get_real_time_data_from_naver($pdo);
        if ($is_success) {
            echo "시세 업데이트가 완료되었습니다.";
        } else {
            http_response_code(500);
            echo "데이터가 없거나 업데이트에 실패했습니다.";
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo "서버 에러: " . $e->getMessage();
    }
    exit;
}

// ==========================================================
// mode=ga  네이버 뉴스 기사 본문
// ==========================================================
function mode_ga($pdo) {
    $url = $_POST['url'] ?? '';
    if (empty($url)) { echo "URL이 없습니다."; exit; }
    $api = new NaverFinanceAPI();
    echo $api->getNaverArticleBody($url);
    exit;
}

// ==========================================================
// mode=etf_update  ETF 편입종목 업데이트
// ==========================================================
function mode_etf_update($pdo) {
    $etfRepo = new StockRepository($pdo);

    // AJAX 처리
    if (isset($_POST['action'])) {

        if ($_POST['action'] === 'update_single_etf') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $updatedCount = $etfRepo->updateEtfHoldings($_POST['etf_code']);
                echo json_encode($updatedCount >= 0
                    ? ['status' => 'success', 'count' => $updatedCount]
                    : ['status' => 'error',   'message' => 'DB 업데이트 실패']
                );
            } catch (\Throwable $th) {
                echo json_encode(['status' => 'fatal_error', 'message' => $th->getMessage()]);
            }
            exit;
        }

        if ($_POST['action'] === 'log_update_complete') {
            header('Content-Type: application/json; charset=utf-8');
            data_upTime('auto_etf_naver_update', 'update', $pdo);
            echo json_encode(['status' => 'success']);
            exit;
        }
    }

    // 화면 그리기
    $totalAllEtfs = (int)$pdo->query("SELECT COUNT(*) FROM all_etf_info")->fetchColumn();
    if ($totalAllEtfs === 0) $totalAllEtfs = 1;

    $allEtfs = $pdo->query(
        "SELECT i.etf_code, i.etf_name, MAX(h.uDate) AS last_update
         FROM all_etf_info i
         LEFT JOIN all_etf_holdings_info h ON i.etf_code = h.etf_code
         WHERE i.skip_update = 0
         GROUP BY i.etf_code
         HAVING last_update IS NULL OR last_update < CURDATE()
         ORDER BY i.etf_code ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $etfJson               = json_encode($allEtfs);
    $pendingCount          = count($allEtfs);
    $alreadyCompletedCount = $totalAllEtfs - $pendingCount;
    $initialPercentage     = floor(($alreadyCompletedCount / $totalAllEtfs) * 100);
    $initialEtaSec         = $pendingCount * 2;
    $initialEtaMins        = floor($initialEtaSec / 60);
    $initialEtaSecs        = $initialEtaSec % 60;
    $initialEtaText        = $initialEtaMins > 0
        ? "약 {$initialEtaMins}분 {$initialEtaSecs}초 예상됨"
        : "약 {$initialEtaSecs}초 예상됨";
    $ajax_url = CUR_PHP . '?mode=etf_update';
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <title>ETF 편입종목 자동 업데이트</title>
        <style>
            body { font-family: 'Malgun Gothic', sans-serif; padding: 20px; }
            .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .table th, .table td { border: 1px solid #ccc; padding: 10px; text-align: center; }
            .table th { background-color: #f8f9fa; }
            .btn-start  { background: #e1234a; color: white; border: none; padding: 10px 20px; font-size: 16px; font-weight: bold; cursor: pointer; border-radius: 5px; }
            .btn-start:disabled { background: #aaa; cursor: not-allowed; }
            .btn-pause  { background: #f39c12; color: white; border: none; padding: 10px 20px; font-size: 16px; font-weight: bold; cursor: pointer; border-radius: 5px; display: none; margin-left: 10px; }
            .status-pending { color: #888; }
            .status-running { color: #0052a4; font-weight: bold; }
            .status-done    { color: green;  font-weight: bold; }
            .status-error   { color: red;    font-weight: bold; }
            .success-msg { margin-top: 40px; padding: 30px; background: #e8f5e9; color: #2e7d32; border-radius: 8px; text-align: center; border: 1px solid #c8e6c9; }
            .progress-container { display: flex; align-items: center; gap: 15px; margin-top: 20px; margin-bottom: 15px; }
            .progress-bar-bg   { flex-grow: 1; background-color: #e0e0e0; border-radius: 10px; height: 24px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.2); }
            .progress-bar-fill { height: 100%; background-color: #4caf50; transition: width 0.4s ease-in-out; }
            .progress-text { font-weight: bold; color: #333; white-space: nowrap; font-size: 15px; }
            .eta-text      { font-weight: bold; color: #e1234a; white-space: nowrap; font-size: 15px; min-width: 150px; text-align: right; }
        </style>
    </head>
    <body>
    <h2>📊 ETF 편입종목 전체 자동 업데이트</h2>
    <p>네이버 차단 방지를 위해 <b>2초에 1개씩</b> 진행되며, 이미 오늘 완료된 종목은 생략됩니다.</p>

    <?php if ($pendingCount === 0): ?>
        <div class="success-msg">
            <h3 style="margin:0;">🎉 오늘자 ETF 편입종목 업데이트가 모두 완료되었습니다!</h3>
            <p style="margin-top:10px;">전체 <?= $totalAllEtfs ?>개 ETF 모두 최신 상태입니다.</p>
        </div>
    <?php else: ?>
        <button id="startBtn" class="btn-start" onclick="startBatchUpdate()">🚀 업데이트 시작</button>
        <button id="pauseBtn" class="btn-pause" onclick="togglePause()">⏸ 일시정지</button>
        <div class="progress-container">
            <div class="progress-bar-bg">
                <div id="progressBar" class="progress-bar-fill" style="width:<?= $initialPercentage ?>%;"></div>
            </div>
            <span id="progressText" class="progress-text"><?= $initialPercentage ?>% (<?= $alreadyCompletedCount ?> / <?= $totalAllEtfs ?>)</span>
            <span id="etaText"      class="eta-text">남은 시간: <?= $initialEtaText ?></span>
        </div>
        <table class="table">
            <thead>
                <tr><th>순번</th><th>ETF 코드</th><th>ETF 명</th><th>최근 업데이트일</th><th>진행 상태</th></tr>
            </thead>
            <tbody>
                <?php foreach ($allEtfs as $i => $etf): ?>
                <tr id="row_<?= $etf['etf_code'] ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= $etf['etf_code'] ?></td>
                    <td style="text-align:left;"><?= $etf['etf_name'] ?></td>
                    <td class="date-cell"><?= $etf['last_update'] ?: '기록 없음' ?></td>
                    <td class="status-cell status-pending">대기 중</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        const AJAX_URL              = '<?= $ajax_url ?>';
        const etfList               = <?= $etfJson ?>;
        const totalAllEtfs          = <?= $totalAllEtfs ?>;
        const alreadyCompletedCount = <?= $alreadyCompletedCount ?>;
        const pendingEtfs           = etfList.length;
        let currentIndex            = 0;
        let isRunning               = false;
        let sessionStartTime        = 0;
        let sessionProcessedCount   = 0;
        const todayStr = new Date().toISOString().slice(0, 10);

        function startBatchUpdate() {
            if (!confirm('남은 ' + pendingEtfs + '개 ETF의 업데이트를 시작하시겠습니까?')) return;
            document.getElementById('startBtn').disabled = true;
            document.getElementById('startBtn').innerText = '🔄 업데이트 진행 중...';
            const pauseBtn = document.getElementById('pauseBtn');
            pauseBtn.style.display = 'inline-block';
            pauseBtn.innerText = '⏸ 일시정지';
            isRunning = true;
            sessionStartTime = Date.now();
            sessionProcessedCount = 0;
            processNextEtf();
        }

        function togglePause() {
            const pauseBtn = document.getElementById('pauseBtn');
            if (isRunning) {
                isRunning = false;
                pauseBtn.innerText = '▶ 다시 시작';
                document.getElementById('startBtn').innerText = '⏸ 일시정지 됨';
                document.getElementById('etaText').innerText = '일시정지됨';
            } else {
                isRunning = true;
                pauseBtn.innerText = '⏸ 일시정지';
                document.getElementById('startBtn').innerText = '🔄 업데이트 진행 중...';
                sessionStartTime = Date.now();
                sessionProcessedCount = 0;
                processNextEtf();
            }
        }

        function processNextEtf() {
            if (!isRunning) return;
            if (currentIndex >= pendingEtfs) {
                const fd = new FormData();
                fd.append('action', 'log_update_complete');
                fetch(AJAX_URL, { method: 'POST', body: fd }).finally(() => {
                    alert('🎉 모든 ETF 업데이트가 완료되었습니다!');
                    document.getElementById('startBtn').innerText = '✅ 업데이트 완료';
                    document.getElementById('pauseBtn').style.display = 'none';
                    document.getElementById('etaText').innerText = '업데이트 완료!';
                });
                return;
            }
            const etf        = etfList[currentIndex];
            const rowId      = 'row_' + etf.etf_code;
            const statusCell = document.querySelector('#' + rowId + ' .status-cell');
            statusCell.className = 'status-cell status-running';
            statusCell.innerText = '🔄 데이터 수집 중...';
            const fd = new FormData();
            fd.append('action', 'update_single_etf');
            fd.append('etf_code', etf.etf_code);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(r => r.text())
                .then(text => {
                    try { return JSON.parse(text); }
                    catch(e) { console.error('서버 에러:', text); throw new Error('서버 에러'); }
                })
                .then(data => {
                    if (data.status === 'success') {
                        statusCell.className = 'status-cell status-done';
                        statusCell.innerText = '✅ 완료 (' + data.count + '종목)';
                        document.querySelector('#' + rowId + ' .date-cell').innerText = todayStr;
                    } else {
                        statusCell.className = 'status-cell status-error';
                        statusCell.innerText = '❌ 실패: ' + (data.message || '알 수 없음');
                    }
                })
                .catch(() => {
                    statusCell.className = 'status-cell status-error';
                    statusCell.innerText = '❌ 통신 에러';
                    isRunning = false;
                    document.getElementById('pauseBtn').innerText = '▶ 에러로 멈춤 (다시 시작)';
                })
                .finally(() => {
                    currentIndex++;
                    sessionProcessedCount++;
                    updateProgress();
                    if (isRunning && currentIndex < pendingEtfs) {
                        setTimeout(processNextEtf, 2000);
                    } else if (currentIndex >= pendingEtfs) {
                        processNextEtf();
                    }
                });
        }

        function updateProgress() {
            const completed  = alreadyCompletedCount + currentIndex;
            const percentage = Math.floor(completed / totalAllEtfs * 100);
            document.getElementById('progressBar').style.width  = percentage + '%';
            document.getElementById('progressText').innerText   = percentage + '% (' + completed + ' / ' + totalAllEtfs + ')';
            if (isRunning && sessionProcessedCount > 0 && currentIndex < pendingEtfs) {
                const avg    = (Date.now() - sessionStartTime) / sessionProcessedCount;
                const remSec = Math.ceil((pendingEtfs - currentIndex) * avg / 1000);
                document.getElementById('etaText').innerText = remSec > 60
                    ? '남은 시간: 약 ' + Math.floor(remSec / 60) + '분 ' + (remSec % 60) + '초'
                    : '남은 시간: 약 ' + remSec + '초';
            }
        }
        </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================================
// mode=display  CSV 데이터 업로드
// ==========================================================
function mode_display($pdo) {
    $test_on = 0;

    $UPLOAD_GROUPS = [
        'basic' => '📌 기본 정보 (부정기 업데이트)',
        'daily' => '📊 일일 시세 (매일 수시 업데이트)',
    ];

    $UPLOAD_CONFIG = [
        'etf_info' => ['group' => 'basic', 'title' => '📋 ETF 기본정보',  'table_name' => 'all_etf_info',   'auto_reset' => false, 'requires_date' => false],
        'etf'      => ['group' => 'daily', 'title' => '📈 ETF 시세',       'table_name' => 'all_etf_price',  'auto_reset' => true,  'requires_date' => true],
        'stock'    => ['group' => 'daily', 'title' => '🏢 주식 시세',      'table_name' => 'all_stock_info', 'auto_reset' => true,  'requires_date' => false],
    ];

    $message = "";
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        $data_type = $_POST['data_type'] ?? '';
        $ref_date  = $_POST['ref_date']  ?? '';
        $file      = $_FILES['csv_file'];

        if (isset($UPLOAD_CONFIG[$data_type]) && $file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                $pdo->beginTransaction();
                try {
                    $config    = $UPLOAD_CONFIG[$data_type];
                    $is_reset  = $config['auto_reset'];
                    $success_count = 0;

                    switch ($data_type) {
                        case 'etf':      $success_count = process_etf_csv($pdo, $handle, $ref_date, $is_reset);      break;
                        case 'etf_info': $success_count = process_etf_info_csv($pdo, $handle, $ref_date, $is_reset); break;
                        case 'stock':    $success_count = process_stock_csv($pdo, $handle, $ref_date, $is_reset);    break;
                    }
                    $pdo->commit();
                    $reset_msg = $is_reset ? "(기존 데이터 초기화 됨)" : "(기존 데이터에 누적 됨)";
                    $message = "<div class='alert alert-success'>
                        <strong>✅ DB 저장 완료! {$reset_msg}</strong><br>
                        [{$config['title']}] 기준 날짜: {$ref_date}<br>
                        총 <b>" . number_format($success_count) . "건</b>의 데이터가 안전하게 입력되었습니다.
                    </div>";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "<div class='alert alert-danger'>❌ 오류: " . $e->getMessage() . "</div>";
                }
                fclose($handle);
            }
        } else {
            $message = "<div class='alert alert-danger'>❌ 올바른 파일이 아니거나 허용되지 않은 데이터 종류입니다.</div>";
        }
    }
    ?>
    <style>
        .dashboard-container { flex: 1; padding: 40px 20px; overflow: auto; display: flex; justify-content: center; background-color: #f0f2f5; }
        .upload-card { background: #ffffff; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; width: 100%; max-width: 600px; padding: 30px; height: fit-content; }
        .upload-card h2 { margin-top: 0; color: #2c3e50; font-size: 22px; margin-bottom: 20px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; }
        .btn-popup { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 16px; background-color: #2c3e50; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: all 0.2s; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn-popup:hover { background-color: #1a252f; transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
        .form-group { margin-bottom: 25px; }
        .form-group label.title-label { display: block; font-weight: bold; color: #2c3e50; margin-bottom: 10px; font-size: 16px; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e0; border-radius: 6px; font-size: 15px; box-sizing: border-box; font-family: inherit; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
        input[type="file"] { background-color: #f8fafc; cursor: pointer; }
        .radio-card-group { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
        .radio-card-group input[type="radio"] { display: none; }
        .radio-card-group .card-label { display: flex; align-items: center; justify-content: center; padding: 14px 10px; border: 2px solid #e2e8f0; border-radius: 8px; text-align: center; cursor: pointer; font-size: 15px; font-weight: 700; color: #4a5568; background-color: #ffffff; transition: all 0.25s; height: 100%; box-sizing: border-box; word-break: keep-all; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .radio-card-group .card-label:hover { background-color: #f8fafc; border-color: #cbd5e0; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .radio-card-group input[type="radio"]:checked + .card-label { border-color: #3498db; background-color: #ebf8ff; color: #2b6cb0; box-shadow: 0 0 0 3px rgba(52,152,219,0.2); transform: translateY(0); }
        .btn-submit { width: 100%; padding: 14px; background-color: #3498db; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background-color 0.2s; }
        .btn-submit:hover { background-color: #2980b9; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; line-height: 1.6; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger  { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>

    <div class='dashboard-container'>
        <div class='upload-card'>
            <h2>📊 데이터 자동 등록기</h2>
            <div style="display:flex; gap:10px; margin-bottom:20px;">
                <button type="button" class="btn-popup" style="flex:1; margin-bottom:0;" onclick="openKrxPopup()">
                    ⬇️ 한국거래소(KRX) 데이터 다운로드
                </button>
                <button type="button" class="btn-popup" style="flex:1; margin-bottom:0; background-color:#2980b9;" onclick="openEtfUpdatePopup()">
                    🚀 ETF 편입종목 자동 업데이트
                </button>
            </div>

            <?= $message ?>

            <form action="<?= CUR_PHP ?>?mode=display" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="title-label">1. 데이터 종류 선택</label>
                    <?php foreach ($UPLOAD_GROUPS as $group_key => $group_title): ?>
                    <div style="margin-bottom:25px;">
                        <h4 style="margin-top:0; margin-bottom:12px; font-size:15px; color:#718096; border-bottom:2px solid #edf2f7; padding-bottom:8px;"><?= $group_title ?></h4>
                        <div class="radio-card-group">
                            <?php foreach ($UPLOAD_CONFIG as $key => $info): ?>
                                <?php if ($info['group'] === $group_key): ?>
                                <input type="radio" name="data_type" id="type_<?= $key ?>" value="<?= $key ?>"
                                       data-reset="<?= $info['auto_reset'] ? 'true' : 'false' ?>"
                                       data-date="<?= $info['requires_date'] ? 'true' : 'false' ?>" required>
                                <label for="type_<?= $key ?>" class="card-label"><?= $info['title'] ?></label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="reset_warning_msg" style="display:none; margin-bottom:25px; padding:15px; border-radius:8px; background:#fdf2f2; border:1px solid #fadbd8; color:#e74c3c; font-weight:bold; font-size:14px; align-items:center; gap:8px;">
                    🚨 주의: 업로드 시 기존 데이터를 모두 삭제하고 새로 등록합니다. (AUTO_INCREMENT 초기화)
                </div>

                <div class="form-group" id="date_input_group" style="display:none;">
                    <label for="ref_date" class="title-label">2. 데이터 기준 날짜</label>
                    <input type="date" name="ref_date" id="ref_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label for="csv_file" class="title-label">3. CSV 파일 첨부 및 분석</label>
                    <div style="display:flex; gap:10px;">
                        <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required style="flex:1;">
                        <button type="button" class="btn-popup" style="width:auto; margin-bottom:0; background-color:#f39c12; padding:12px 20px;" onclick="previewCsv()">
                            🔍 파일 미리보기
                        </button>
                    </div>
                </div>

                <div id="preview_area" style="display:none; margin-bottom:25px; padding:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                    <h3 style="margin-top:0; color:#2c3e50; font-size:16px;">🔍 데이터 매핑 분석 (상위 3개)</h3>
                    <div id="preview_content" style="overflow-x:auto;"></div>
                </div>

                <button type="submit" class="btn-submit">서버로 데이터 전송하기</button>
            </form>
        </div>
    </div>

    <script>
    function openKrxPopup() {
        var url = "http://data.krx.co.kr/contents/MDC/MDI/mdiLoader/index.cmd?menuId=MDC0201020101";
        var w = 1300, h = 1000;
        window.open(url, 'krxPopup', 'width='+w+',height='+h+',left='+Math.round((screen.width-w)/2)+',top='+Math.round((screen.height-h)/2)+',resizable=yes,scrollbars=yes');
    }
    function openEtfUpdatePopup() {
        var url = "<?= CUR_PHP ?>?mode=etf_update";
        var w = 1000, h = 800;
        window.open(url, 'etfUpdatePopup', 'width='+w+',height='+h+',left='+Math.round((screen.width-w)/2)+',top='+Math.round((screen.height-h)/2)+',resizable=yes,scrollbars=yes');
    }
    document.addEventListener('DOMContentLoaded', function() {
        const radioButtons = document.querySelectorAll('input[name="data_type"]');
        const dateGroup    = document.getElementById('date_input_group');
        const dateInput    = document.getElementById('ref_date');
        const warningMsg   = document.getElementById('reset_warning_msg');
        radioButtons.forEach(radio => {
            radio.addEventListener('change', function() {
                const needsDate = this.getAttribute('data-date')  === 'true';
                const autoReset = this.getAttribute('data-reset') === 'true';
                dateGroup.style.display  = needsDate ? 'block' : 'none';
                needsDate ? dateInput.setAttribute('required', 'required') : dateInput.removeAttribute('required');
                warningMsg.style.display = autoReset ? 'flex' : 'none';
            });
        });
    });
    function previewCsv() {
        const file = document.getElementById('csv_file').files[0];
        if (!file) { alert('분석할 CSV 파일을 먼저 첨부해주세요!'); return; }
        const reader = new FileReader();
        reader.readAsArrayBuffer(file);
        reader.onload = function(e) {
            const csvText  = new TextDecoder('euc-kr').decode(e.target.result);
            const lines    = csvText.split(/\r\n|\n/).filter(l => l.trim() !== '');
            const maxLines = Math.min(lines.length, 4);
            let html = '<table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">';
            for (let i = 0; i < maxLines; i++) {
                const cols = parseCSVLine(lines[i]);
                html += '<tr>';
                cols.forEach((col, idx) => {
                    const c = col.trim();
                    html += i === 0
                        ? `<th style="padding:10px; border:1px solid #cbd5e0; background:#e2e8f0; white-space:nowrap; font-weight:bold;"><div style="color:#e74c3c; font-size:11px; margin-bottom:4px;">data[${idx}]</div>${c}</th>`
                        : `<td style="padding:8px 10px; border:1px solid #e2e8f0; white-space:nowrap; color:#4a5568;">${c}</td>`;
                });
                html += '</tr>';
            }
            html += '</table>';
            document.getElementById('preview_content').innerHTML = html;
            document.getElementById('preview_area').style.display = 'block';
        };
    }
    function parseCSVLine(text) {
        let ret = [], cur = '', inQuote = false;
        for (let i = 0; i < text.length; i++) {
            const char = text[i];
            if (inQuote) {
                if (char === '"') { if (i+1 < text.length && text[i+1] === '"') { cur += '"'; i++; } else { inQuote = false; } }
                else { cur += char; }
            } else {
                if (char === '"') { inQuote = true; }
                else if (char === ',') { ret.push(cur); cur = ''; }
                else { cur += char; }
            }
        }
        ret.push(cur);
        return ret;
    }
    </script>
    <?php
}