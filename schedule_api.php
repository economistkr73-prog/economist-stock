<?php
ob_start(); // included 파일 stray output 방지
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

// 지도/지오코딩 키 (없으면 GeoCoder 가 안내 메시지 반환)
if (file_exists("./env/maps.inc"))  require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc")) require_once "./env/kakao.inc";

ob_clean(); // stray output 제거 후 JSON 헤더 출력
header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? 'calendar';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==========================================================
// 紐⑤뱢 ?쇱슦??// ==========================================================
try {
    switch ($module) {
        case 'calendar': api_calendar($action, $pdo); break;
        case 'contacts': api_contacts($action, $pdo); break;
        case 'projects': api_projects($action, $pdo); break;
        case 'geo':      api_geo($action);            break;
        // case 'kakao':    api_kakao($action, $pdo);    break;
        // case 'progress': api_progress($action, $pdo); break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown module: {$module}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}

// ==========================================================
// 罹섎┛??紐⑤뱢
// ==========================================================
function api_calendar(string $action, PDO $pdo): void {
    $sch = new Schedule($pdo);

    switch ($action) {
        case 'list':
            $view  = $_GET['view'] ?? 'month';
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('m'));

            if ($view === 'month') {
                $first = sprintf('%04d-%02d-01', $year, $month);
                $last  = date('Y-m-t', strtotime($first));
                // 달력 그리드는 이전월 말~익월 초 셀을 포함하므로 6주 범위로 확장
                $sDow  = (int)date('w', strtotime($first));        // 1일의 요일(0=일)
                $eDow  = (int)date('w', strtotime($last));         // 말일의 요일
                $start = date('Y-m-d', strtotime($first . ' -' . $sDow . ' days'));
                $end   = date('Y-m-d', strtotime($last  . ' +' . (6 - $eDow) . ' days'));
            } elseif ($view === 'week') {
                $start = $_GET['start'] ?? date('Y-m-d');
                $end   = date('Y-m-d', strtotime($start . ' +6 days'));
            } elseif ($view === 'day') {
                $start = $end = $_GET['date'] ?? date('Y-m-d');
            } else {
                $start = $_GET['start'] ?? date('Y-01-01');
                $end   = $_GET['end']   ?? date('Y-12-31');
            }

            $projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
            $events   = $sch->listByRange($start, $end, $projectId);
            try {
                $hApi     = new HolidayAPI($pdo);
                $holidays = $hApi->getAsEvents($start, $end);
                $events   = array_merge($holidays, $events);
            } catch (Exception $e) { /* HolidayAPI ?놁쑝硫?臾댁떆 */ }
            // 李몄꽍??蹂묓빀
            try {
                $contact = new Contact($pdo);
                $sids = array_filter(array_column($events, 'id'), 'is_numeric');
                $aMap = $contact->getAttendeesMap($sids);
                foreach ($events as &$ev) {
                    $ev['attendees'] = $aMap[$ev['id']] ?? [];
                }
                unset($ev);
            } catch (Exception $e) { /* 臾댁떆 */ }
            echo json_encode(['ok' => true, 'data' => $events]);
            break;

        case 'create':
            $d  = json_decode(file_get_contents('php://input'), true);
            // 중복 체크: force=true 이면 건너뜀 (사용자가 확인 후 재요청)
            if (empty($d['force'])) {
                $title   = trim($d['title'] ?? '');
                $startDt = $d['start_dt'] ?? null;
                if ($title !== '' && $startDt !== null) {
                    $dateOnly = substr($startDt, 0, 10);
                    $chk = $pdo->prepare(
                        "SELECT id FROM tbl_schedule WHERE title=:t AND start_dt LIKE :d LIMIT 1"
                    );
                    $chk->execute([':t' => $title, ':d' => $dateOnly . '%']);
                    if ($chk->fetch()) {
                        echo json_encode(['ok' => false, 'dup' => true,
                            'msg' => '같은 날짜에 동일 제목의 일정이 이미 있습니다.']);
                        break;
                    }
                }
            }
            $id = $sch->create($d);
            // 李몄꽍???곌껐
            if (isset($d['attendees']) && is_array($d['attendees'])) {
                (new Contact($pdo))->setAttendees($id, $d['attendees']);
            }
            // ?쒓컙 寃뱀묠 ?먮룞 ?쒖뿰 (timed ?대깽?몃쭔)
            $shifted = [];
            if (($d['event_type'] ?? '') === 'timed' && !empty($d['start_dt']) && !empty($d['end_dt'])) {
                $shifted = adjust_overlaps($pdo, $id, $d['start_dt'], $d['end_dt']);
            }
            echo json_encode(['ok' => true, 'id' => $id, 'shifted' => $shifted]);
            break;

        case 'update':
            $d = json_decode(file_get_contents('php://input'), true);
            $sch->update($d);
            // 李몄꽍??媛깆떊
            if (isset($d['attendees']) && is_array($d['attendees']) && !empty($d['id'])) {
                (new Contact($pdo))->setAttendees((int)$d['id'], $d['attendees']);
            }
            // ?쒓컙 寃뱀묠 ?먮룞 ?쒖뿰 (timed ?대깽?몃쭔)
            $shifted = [];
            if (($d['event_type'] ?? '') === 'timed' && !empty($d['start_dt']) && !empty($d['end_dt'])) {
                $shifted = adjust_overlaps($pdo, (int)$d['id'], $d['start_dt'], $d['end_dt']);
            }
            echo json_encode(['ok' => true, 'shifted' => $shifted]);
            break;

        case 'toggle_done':
            $d = json_decode(file_get_contents('php://input'), true);
            $sch->toggleDone((int)($d['id'] ?? 0));
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $sch->delete($id);
            echo json_encode(['ok' => true]);
            break;

        // 諛섎났 ?쇱젙 踰붿쐞蹂??꾨즺 泥섎━
        case 'toggle_done_scoped':
            $d = json_decode(file_get_contents('php://input'), true);
            $id       = (int)($d['id']        ?? 0);
            $scope    = $d['scope']            ?? 'all';
            $originDt = $d['origin_dt']        ?? date('Y-m-d');
            if ($scope === 'all') {
                $sch->toggleDone($id);
            } else if ($scope === 'one') {
                // ???좎쭨留??덉쇅 ?깅줉 ???꾨즺???⑤룆 ?대깽???앹꽦
                $sch->deleteScoped($id, 'one', $originDt);
                $parent = $sch->getById($id);
                if ($parent) {
                    $parent['start_dt']   = $originDt . substr($parent['start_dt'] ?? ($originDt.' 00:00:00'), 10);
                    $parent['recur_rule'] = null;
                    $parent['_single']    = 1;   // 湲곕뀗???먮룞 諛섎났 諛⑹?
                    $parent['is_done']    = 1;
                    unset($parent['id']);
                    $sch->create($parent);
                }
            } else if ($scope === 'future') {
                $sch->deleteScoped($id, 'future', $originDt);
            }
            echo json_encode(['ok' => true]);
            break;

        // ?묐젰 ???뚮젰 蹂??        case 'solar_to_lunar':
            $date   = $_GET['date'] ?? date('Y-m-d');
            $result = LunarCalendar::solarToLunar($date);
            echo json_encode(['ok' => true, 'data' => $result]);
            break;

        // ?뚮젰 ???묐젰 蹂??(bulk: ?year=2026&items=[[m,d],[m,d],...])
        case 'lunar_to_solar':
            $lunarYear = (int)($_GET['year']  ?? date('Y'));
            $items     = json_decode($_GET['items'] ?? '[]', true);
            $out       = [];
            foreach ((array)$items as $it) {
                $lm = (int)($it[0] ?? 0);
                $ld = (int)($it[1] ?? 0);
                if ($lm && $ld) {
                    $out["{$lm},{$ld}"] = LunarCalendar::lunarToSolar($lunarYear, $lm, $ld);
                }
            }
            echo json_encode(['ok' => true, 'data' => $out]);
            break;

        // 諛섎났 ?쇱젙 踰붿쐞蹂???젣: scope=one|future|all + origin_dt
        case 'delete_scoped':
            $d = json_decode(file_get_contents('php://input'), true);
            $sch->deleteScoped(
                (int)($d['id']        ?? 0),
                $d['scope']           ?? 'all',
                $d['origin_dt']       ?? date('Y-m-d')
            );
            echo json_encode(['ok' => true]);
            break;

        // 諛섎났 ?쇱젙 踰붿쐞蹂??섏젙: scope=one|future|all + origin_dt
        case 'update_scoped':
            $d = json_decode(file_get_contents('php://input'), true);
            $sch->updateScoped(
                (int)($d['id']        ?? 0),
                $d['scope']           ?? 'all',
                $d['origin_dt']       ?? date('Y-m-d'),
                $d
            );
            echo json_encode(['ok' => true]);
            break;

        case 'all_anniversaries':
            echo json_encode(['ok' => true, 'data' => $sch->listAllAnniversaries()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}

// ==========================================================
// ?쒓컙 寃뱀묠 ?먮룞 ?쒖뿰
// 湲곗? ?대깽??newId)? 媛숈? ??寃뱀튂??timed ?대깽?몃? 1???쒖뿰
// - 鍮꾨컲蹂? start_dt/end_dt 吏곸젒 UPDATE
// - 諛섎났:   ?뱀씪 exception ?깅줉 + ?뱀씪 ?⑤룆 ?대깽???앹꽦
// ==========================================================
function adjust_overlaps(PDO $pdo, int $newId, string $newStart, string $newEnd): array {
    $date       = substr($newStart, 0, 10);
    $newStartTs = strtotime($newStart);
    $newEndTs   = strtotime($newEnd);
    $shifted    = [];

    // ?? 1) 鍮꾨컲蹂?timed ?대깽?????????????????????????????
    $stmt = $pdo->prepare(
        "SELECT id, start_dt, end_dt FROM tbl_schedule
         WHERE event_type = 'timed'
           AND is_allday  = 0
           AND id        != :newId
           AND DATE(start_dt) = :date
           AND (recur_rule IS NULL OR recur_rule = '')"
    );
    $stmt->execute([':newId' => $newId, ':date' => $date]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        $evStartTs = strtotime($ev['start_dt']);
        $evEndTs   = strtotime($ev['end_dt']);
        if ($newEndTs <= $evStartTs || $newStartTs >= $evEndTs) continue;

        [$newEvStart, $newEvEnd] = calc_shift($newStartTs, $newEndTs, $evStartTs, $evEndTs);

        $pdo->prepare("UPDATE tbl_schedule SET start_dt=:s, end_dt=:e WHERE id=:id")
            ->execute([':s' => $newEvStart, ':e' => $newEvEnd, ':id' => $ev['id']]);

        $shifted[] = ['id' => $ev['id'], 'start_dt' => $newEvStart, 'end_dt' => $newEvEnd];
    }

    // ?? 2) 諛섎났 timed ?대깽?????뱀씪 ?몄뒪?댁뒪留??섏젙 (scope=one) ??
    $sch       = new Schedule($pdo);
    $instances = $sch->listByRange($date, $date);

    foreach ($instances as $inst) {
        if (($inst['event_type'] ?? '') !== 'timed') continue;
        if ((int)($inst['is_allday'] ?? 0)) continue;
        if (empty($inst['recur_rule'])) continue;
        if ((int)($inst['id'] ?? 0) === $newId) continue;

        $evStartTs = strtotime($inst['start_dt']);
        $evEndTs   = strtotime($inst['end_dt']);
        if ($newEndTs <= $evStartTs || $newStartTs >= $evEndTs) continue;

        [$newEvStart, $newEvEnd] = calc_shift($newStartTs, $newEndTs, $evStartTs, $evEndTs);

        // 湲곗〈 "?뱀씪留??섏젙" 濡쒖쭅 ?ъ궗??        $data             = $inst;
        $data['start_dt'] = $newEvStart;
        $data['end_dt']   = $newEvEnd;
        $sch->updateScoped((int)$inst['id'], 'one', $date, $data);

        $shifted[] = ['id' => (int)$inst['id'], 'start_dt' => $newEvStart, 'end_dt' => $newEvEnd];
    }

    return $shifted;
}

function calc_shift(int $newStartTs, int $newEndTs, int $evStartTs, int $evEndTs): array {
    $duration = $evEndTs - $evStartTs;
    if ($newStartTs <= $evStartTs) {
        // ?좉퇋媛 ?욎そ ??湲곗〈???ㅻ줈
        return [date('Y-m-d H:i:s', $newEndTs), date('Y-m-d H:i:s', $newEndTs + $duration)];
    } else {
        // ?좉퇋媛 ?ㅼそ ??湲곗〈???욎쑝濡?        return [date('Y-m-d H:i:s', $newStartTs - $duration), date('Y-m-d H:i:s', $newStartTs)];
    }
}

// ==========================================================
// 二쇱냼濡?紐⑤뱢
// ==========================================================
function api_contacts(string $action, PDO $pdo): void {
    $contact = new Contact($pdo);

    switch ($action) {
        case 'list':
            $search = $_GET['search'] ?? '';
            $group  = $_GET['group']  ?? '';
            echo json_encode(['ok' => true, 'data' => $contact->listAll($search, $group)]);
            break;

        case 'groups':
            echo json_encode(['ok' => true, 'data' => $contact->groups()]);
            break;

        // CSV(紐낇븿) ?쇨큵 ?깅줉
        case 'import_csv':
            $d    = json_decode(file_get_contents('php://input'), true);
            $rows = $d['rows'] ?? [];
            if (!is_array($rows) || empty($rows)) {
                echo json_encode(['ok' => false, 'msg' => '?깅줉???곗씠?곌? ?놁뒿?덈떎.']); break;
            }
            echo json_encode(['ok' => true, 'data' => $contact->importRows($rows)]);
            break;

        // 구글 드라이브 CSV 자동 가져오기
        case 'import_drive':
            if (file_exists("./env/kakao.inc")) require_once "./env/kakao.inc";
            $r = $contact->importFromDrive();
            if (isset($r['error'])) { echo json_encode(['ok'=>false,'msg'=>$r['error']]); break; }
            echo json_encode(['ok' => true, 'data' => $r]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $contact->getById($id)]);
            break;

        case 'create':
            $d  = json_decode(file_get_contents('php://input'), true);
            if (trim($d['name'] ?? '') === '') {
                echo json_encode(['ok' => false, 'msg' => '?대쫫? ?꾩닔?낅땲??']); return;
            }
            $id = $contact->create($d);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        case 'update':
            $d = json_decode(file_get_contents('php://input'), true);
            $contact->update($d);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $contact->delete($id);
            echo json_encode(['ok' => true]);
            break;

        // ?몃Ъ蹂??쇱젙 ?덉뒪?좊━
        case 'history':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $contact->getScheduleHistory($id)]);
            break;

        // ?쇱젙??李몄꽍??紐⑸줉
        case 'attendees':
            $sid = (int)($_GET['schedule_id'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $contact->getAttendees($sid)]);
            break;

        // ?몃Ъ蹂?湲곕뀗??紐⑸줉
        case 'anniversaries':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $contact->getAnniversaries($id)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}


// ==========================================================
// 지오코딩 모듈 (주소 → 좌표)
//   provider: 'naver'(국내) | 'google'(해외) — 사용자가 폼에서 직접 선택
//   GeoCoder 가 좌표를 찾은 제공자와 동일한 provider 를 반환하므로
//   상세 화면에서 같은 지도로 렌더해 핀 어긋남을 방지한다.
// ==========================================================
function api_geo(string $action): void {
    switch ($action) {
        case 'geocode':
            // GET/POST 모두 허용 (저장 흐름에서 fetch GET 으로 호출)
            $provider = $_GET['provider'] ?? $_POST['provider'] ?? 'naver';
            $address  = $_GET['address']  ?? $_POST['address']  ?? '';
            $provider = $provider === 'google' ? 'google' : 'naver';
            echo json_encode(GeoCoder::geocode($provider, $address), JSON_UNESCAPED_UNICODE);
            break;

        case 'place_search':
            $q = trim($_GET['q'] ?? '');
            if ($q === '') { echo json_encode(['ok'=>false,'msg'=>'검색어를 입력하세요.']); break; }
            if (!defined('KAKAO_REST_API_KEY') || KAKAO_REST_API_KEY === '') {
                echo json_encode(['ok'=>false,'msg'=>'카카오 REST API 키 미설정']); break;
            }
            $url = 'https://dapi.kakao.com/v2/local/search/keyword.json?'
                 . http_build_query(['query' => $q, 'size' => 8]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => ['Authorization: KakaoAK ' . KAKAO_REST_API_KEY],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errNo    = curl_errno($ch);
            $errMsg   = curl_error($ch);
            curl_close($ch);
            if ($errNo) {
                echo json_encode(['ok'=>false,'msg'=>"curl오류({$errNo}): {$errMsg}"]); break;
            }
            if ($httpCode !== 200) {
                echo json_encode(['ok'=>false,'msg'=>"HTTP {$httpCode}",'body'=>substr($body,0,200)]); break;
            }
            $data = json_decode($body, true);
            $places = array_map(fn($d) => [
                'name'    => $d['place_name'],
                'address' => $d['road_address_name'] ?: $d['address_name'],
                'lat'     => $d['y'],
                'lng'     => $d['x'],
                'phone'   => $d['phone'] ?? '',
                'category'=> $d['category_name'] ?? '',
            ], $data['documents'] ?? []);
            echo json_encode(['ok' => true, 'places' => $places], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}

// ==========================================================
// 프로젝트/그룹 모듈
// ==========================================================
function api_projects(string $action, PDO $pdo): void {
    $proj = new Project($pdo);

    switch ($action) {
        // 전체 목록 (사이드패널용)
        case 'list':
            echo json_encode(['ok' => true, 'data' => $proj->listAll()]);
            break;

        // 드롭다운 선택용 간단 목록
        case 'select':
            echo json_encode(['ok' => true, 'data' => $proj->listForSelect()]);
            break;

        // 단건 조회
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $proj->getById($id)]);
            break;

        // 생성
        case 'create':
            $d  = json_decode(file_get_contents('php://input'), true);
            if (trim($d['title'] ?? '') === '') {
                echo json_encode(['ok' => false, 'msg' => '제목은 필수입니다.']); return;
            }
            $id = $proj->create($d);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        // 수정
        case 'update':
            $d  = json_decode(file_get_contents('php://input'), true);
            $id = (int)($d['id'] ?? 0);
            if (!$id) { echo json_encode(['ok' => false, 'msg' => 'id 없음']); return; }
            $proj->update($id, $d);
            echo json_encode(['ok' => true]);
            break;

        // 삭제
        case 'delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $proj->delete($id);
            echo json_encode(['ok' => true]);
            break;

        // 완료 토글
        case 'toggle_done':
            $d = json_decode(file_get_contents('php://input'), true);
            echo json_encode($proj->toggleDone((int)($d['id'] ?? 0)));
            break;

        // 프로젝트/그룹에 직접 속한 일정 목록 (+ 그룹이면 포함 프로젝트 목록)
        case 'items':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode([
                'ok'       => true,
                'data'     => $proj->getItems($id),
                'projects' => $proj->getChildProjects($id),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}
