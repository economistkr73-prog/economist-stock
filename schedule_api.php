<?php
ob_start(); // included 파일 stray output 방지
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

// 지도/지오코딩 키 (없으면 GeoCoder 가 안내 메시지 반환)
if (file_exists("./env/maps.inc"))   require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc"))  require_once "./env/kakao.inc";
if (file_exists("./env/gdrive.inc")) require_once "./env/gdrive.inc"; // 여행 동기화용 Drive 키

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
        case 'habit':    api_habit($action, $pdo);    break;
        case 'goal':     api_goal($action, $pdo);     break;
        case 'geo':      api_geo($action);            break;
        case 'travel':   api_travel($action, $pdo);   break;
        case 'attach':   api_attach($action, $pdo);   break;
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
// ==========================================================
// 첨부 이미지 모듈 — DB(tbl_schedule_attach BLOB) 목록/서빙
//   action=list : 일정의 첨부 메타[{id,w,h}]  (썸네일 참조용)
//   action=img  : 이미지 1건 바이너리 서빙     (<img src>로 사용)
// 저장(삽입/삭제)은 calendar create/update 에서 syncAttach 로 처리
// ==========================================================
function api_attach(string $action, PDO $pdo): void {
    $sch = new Schedule($pdo);

    if ($action === 'list') {
        $id = (int)($_GET['id'] ?? 0);
        echo json_encode(['ok'=>true, 'data'=> $id ? $sch->listAttachMeta($id) : []]);
        return;
    }
    if ($action === 'img') {
        $id  = (int)($_GET['id'] ?? 0);
        $row = $id ? $sch->getAttach($id) : null;
        if (!$row) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8', true); echo 'not found'; return; }
        header('Content-Type: ' . $row['mime'], true);   // 상단 application/json 헤더 교체
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . strlen($row['data']));
        echo $row['data'];
        return;
    }
    echo json_encode(['ok'=>false, 'msg'=>'unknown action']);
}

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
            // 활동 메모 개수 병합 (발생일 기준: 반복=origin_dt, 단일=자기 날짜)
            try {
                $logMap = $sch->logCountMap($start, $end);
                foreach ($events as &$ev) {
                    $occ = $ev['origin_dt'] ?? substr((string)($ev['start_dt'] ?? $ev['due_dt'] ?? ''), 0, 10);
                    $ev['log_count'] = $logMap[($ev['id'] ?? '') . '|' . $occ] ?? 0;
                }
                unset($ev);
            } catch (Exception $e) { /* 무시 */ }
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
            $sch->syncAttach($id, [], $d['attach_new'] ?? []);   // 첨부 이미지(신규 일정)
            // 李몄꽍???곌껐
            if (isset($d['attendees']) && is_array($d['attendees'])) {
                (new Contact($pdo))->setAttendees($id, $d['attendees']);
            }
            // ?쒓컙 寃뱀묠 ?먮룞 ?쒖뿰 (timed ?대깽?몃쭔)
            $shifted = [];
            if (($d['event_type'] ?? '') === 'timed' && !empty($d['start_dt']) && !empty($d['end_dt']) && empty($d['is_draft'])) {
                $shifted = adjust_overlaps($pdo, $id, $d['start_dt'], $d['end_dt']);
            }
            echo json_encode(['ok' => true, 'id' => $id, 'shifted' => $shifted]);
            break;

        case 'update':
            $d = json_decode(file_get_contents('php://input'), true);
            $sch->update($d);
            // 첨부 이미지: keep(유지할 기존 id) 외 삭제 + new 삽입
            if (!empty($d['id'])) {
                $sch->syncAttach((int)$d['id'], $d['attach_keep'] ?? [], $d['attach_new'] ?? []);
            }
            // 李몄꽍??媛깆떊
            if (isset($d['attendees']) && is_array($d['attendees']) && !empty($d['id'])) {
                (new Contact($pdo))->setAttendees((int)$d['id'], $d['attendees']);
            }
            // ?쒓컙 寃뱀묠 ?먮룞 ?쒖뿰 (timed ?대깽?몃쭔)
            $shifted = [];
            if (($d['event_type'] ?? '') === 'timed' && !empty($d['start_dt']) && !empty($d['end_dt']) && empty($d['is_draft'])) {
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

        // ?묐젰 ???뚮젰 蹂??
        case 'solar_to_lunar':
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
            // 첨부는 시리즈(원본) 단위 — 전체(all) 수정일 때만 동기화
            if (($d['scope'] ?? 'all') === 'all' && !empty($d['id'])) {
                $sch->syncAttach((int)$d['id'], $d['attach_keep'] ?? [], $d['attach_new'] ?? []);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'all_anniversaries':
            echo json_encode(['ok' => true, 'data' => $sch->listAllAnniversaries()]);
            break;

        // 활동 메모(타임스탬프 로그) — 일정 발생일(occ_date)별 [HH:MM] 메모
        case 'log_list':
            $sid = (int)($_GET['schedule_id'] ?? 0);
            $od  = $_GET['occ_date'] ?? date('Y-m-d');
            echo json_encode(['ok' => true, 'data' => $sid > 0 ? $sch->logList($sid, $od) : []]);
            break;

        case 'log_add':
            $d    = json_decode(file_get_contents('php://input'), true);
            $sid  = (int)($d['schedule_id'] ?? 0);
            $od   = $d['occ_date'] ?? date('Y-m-d');
            $note = trim((string)($d['note'] ?? ''));
            if ($sid <= 0 || $note === '') {
                echo json_encode(['ok' => false, 'msg' => '메모 내용이 비었습니다.']);
                break;
            }
            echo json_encode(['ok' => true, 'data' => $sch->logAdd($sid, $od, $note, $d['time'] ?? null)]);
            break;

        case 'log_delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id > 0) $sch->logDelete($id);
            echo json_encode(['ok' => true]);
            break;

        // 음성 명령 해석: 발화 텍스트 → Claude로 {intent, 날짜/시간/제목/검색어} 파싱
        // intent=find|delete 이면 후보 일정도 함께 조회해 반환 (실행은 프론트에서 사용자 확인 후)
        case 'voice':
            $d    = json_decode(file_get_contents('php://input'), true);
            $text = trim((string)($d['text'] ?? ''));
            if ($text === '') { echo json_encode(['ok' => false, 'msg' => '인식된 음성이 없습니다.']); break; }

            $parsed = voice_parse_claude($text);
            if (empty($parsed['ok'])) {
                echo json_encode(['ok' => false, 'msg' => $parsed['msg'] ?? '음성 해석 실패']);
                break;
            }
            $p = $parsed['parsed'];

            // find/delete: 후보 일정 조회 (제목 키워드 + 기간)
            $candidates = [];
            if (in_array($p['intent'] ?? '', ['find', 'delete'], true)) {
                $from = (!empty($p['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['date_from']))
                        ? $p['date_from'] : date('Y-m-d', strtotime('-31 days'));
                $to   = (!empty($p['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['date_to']))
                        ? $p['date_to'] : date('Y-m-d', strtotime('+366 days'));
                $kw   = trim((string)($p['keyword'] ?? ''));
                $events = $sch->listByRange($from, $to, null);
                foreach ($events as $ev) {
                    if (($ev['event_type'] ?? '') === 'holiday' || ($ev['is_holiday'] ?? '') == '1') continue;
                    if ($kw !== '' && mb_stripos((string)($ev['title'] ?? ''), $kw) === false) continue;
                    $candidates[] = $ev;
                    if (count($candidates) >= 50) break;
                }
            }

            // create + 시각 지정: 같은 시간대에 겹치는 기존 일정 찾기 → 경고용 (등록은 막지 않음)
            $conflicts = [];
            if (($p['intent'] ?? '') === 'create' && empty($p['is_allday'])
                && !empty($p['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['date'])
                && !empty($p['start_time']) && preg_match('/^\d{1,2}:\d{2}$/', $p['start_time'])) {
                $st  = $p['start_time'];
                $et  = (!empty($p['end_time']) && preg_match('/^\d{1,2}:\d{2}$/', $p['end_time']))
                        ? $p['end_time'] : date('H:i', strtotime($st) + 3600);
                $newStart = $p['date'] . ' ' . $st . ':00';
                $newEnd   = $p['date'] . ' ' . $et . ':00';
                foreach ($sch->listByRange($p['date'], $p['date'], null) as $ev) {
                    if (($ev['is_allday'] ?? '') == '1') continue;
                    if (in_array($ev['event_type'] ?? '', ['allday','anniversary','todo','holiday'], true)) continue;
                    if (($ev['is_holiday'] ?? '') == '1') continue;
                    $es = (string)($ev['start_dt'] ?? '');
                    if ($es === '') continue;
                    $ee = (string)($ev['end_dt'] ?? '');
                    if ($ee === '') $ee = date('Y-m-d H:i:s', strtotime($es) + 3600);
                    if ($es < $newEnd && $ee > $newStart) {   // 시간대 겹침
                        $conflicts[] = $ev;
                        if (count($conflicts) >= 10) break;
                    }
                }
            }

            echo json_encode(['ok' => true, 'parsed' => $p, 'candidates' => $candidates,
                              'conflicts' => $conflicts, 'heard' => $text],
                             JSON_UNESCAPED_UNICODE);
            break;

        // 대시보드 이번 달 달성률: 완료 의미 있는 할일(todo)만 집계
        case 'todo_stats':
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('m'));
            $ms = sprintf('%04d-%02d-01', $year, $month);
            $me = date('Y-m-t', strtotime($ms));
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(is_done),0) AS done, COUNT(*) AS total
                  FROM tbl_schedule
                 WHERE event_type='todo' AND due_dt BETWEEN :s AND :e
            ");
            $st->execute([':s' => $ms, ':e' => $me]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['done' => 0, 'total' => 0];
            echo json_encode(['ok' => true,
                'done'  => (int)$r['done'], 'total' => (int)$r['total']]);
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
// ==========================================================
// 음성 명령 해석 — Claude(Haiku)로 발화 텍스트를 구조화 JSON으로 변환
//   반환: ['ok'=>true,'parsed'=>['intent'=>..., 'title'=>..., 'date'=>..., ...]]
//        ['ok'=>false,'msg'=>'...']  (키 미설정/호출 실패/파싱 실패)
// ==========================================================
function voice_parse_claude(string $text): array {
    if (file_exists("./env/anthropic.inc")) require_once "./env/anthropic.inc";
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
    if ($apiKey === '') {
        return ['ok' => false, 'msg' => 'AI 음성 해석 키(ANTHROPIC_API_KEY)가 설정되지 않았습니다.'];
    }

    // 기준 시각(Asia/Seoul)
    try { $now = new DateTime('now', new DateTimeZone('Asia/Seoul')); }
    catch (Throwable $e) { $now = new DateTime(); }
    $dowKr = ['일','월','화','수','목','금','토'];
    $dow   = $dowKr[(int)$now->format('w')];
    $today = $now->format('Y-m-d');
    $hhmm  = $now->format('H:i');

    // 상대 날짜 오인 방지: 이번주/다음주/다다음주 각 요일의 실제 날짜를 표로 제공(모델이 직접 산수하지 않게)
    $sun  = (clone $now)->modify('-' . (int)$now->format('w') . ' days'); // 이번주 일요일(주 시작=일)
    $thisW = $nextW = $afterW = [];
    for ($i = 0; $i < 7; $i++) {
        $thisW[]  = $dowKr[$i] . '=' . (clone $sun)->modify("+$i days")->format('Y-m-d');
        $nextW[]  = $dowKr[$i] . '=' . (clone $sun)->modify('+' . ($i + 7)  . ' days')->format('Y-m-d');
        $afterW[] = $dowKr[$i] . '=' . (clone $sun)->modify('+' . ($i + 14) . ' days')->format('Y-m-d');
    }
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    $weekRef =
        "[날짜 기준표]\n"
      . "- 내일=" . $tomorrow . ", 모레=" . (clone $now)->modify('+2 days')->format('Y-m-d') . "\n"
      . "- 이번주(일~토): " . implode(', ', $thisW) . "\n"
      . "- 다음주(일~토): " . implode(', ', $nextW) . "\n"
      . "- 다다음주(일~토): " . implode(', ', $afterW) . "\n";

    $system =
        "당신은 한국어 음성 일정 비서입니다. 사용자의 발화를 분석해 아래 JSON 객체 하나만 출력하세요. "
        . "설명·인사·마크다운·코드블록 없이 순수 JSON만 출력합니다.\n"
        . "오늘은 {$today} ({$dow}요일), 현재 시각 {$hhmm}, 시간대 Asia/Seoul.\n"
        . $weekRef . "\n"
        . "필드:\n"
        . "{\n"
        . "  \"intent\": \"create|find|delete|unknown\",   // 등록 / 조회·검색 / 삭제 / 판단불가\n"
        . "  \"title\": \"일정 제목(create용. '등록/추가/잡아줘/만들어' 같은 동작어는 빼고 핵심만)\",\n"
        . "  \"date\": \"YYYY-MM-DD (create의 날짜. 상대표현은 위 [날짜 기준표]의 날짜를 그대로 사용. 없으면 오늘)\",\n"
        . "  \"start_time\": \"HH:MM 또는 null (시간 미지정·종일이면 null)\",\n"
        . "  \"end_time\": \"HH:MM 또는 null\",\n"
        . "  \"is_allday\": true/false,\n"
        . "  \"keyword\": \"find/delete 검색어(제목 일부). 없으면 빈 문자열\",\n"
        . "  \"date_from\": \"YYYY-MM-DD 또는 null (find/delete 기간 시작)\",\n"
        . "  \"date_to\": \"YYYY-MM-DD 또는 null (find/delete 기간 끝)\"\n"
        . "}\n\n"
        . "규칙:\n"
        . "- ★날짜는 반드시 위 [날짜 기준표]를 근거로 계산. '이번주 금요일'=이번주 표의 금, '다음주 금요일'/'담주 금요일'=다음주 표의 금, '다다음주 금요일'=다다음주 표의 금. 직접 날짜 산수 금지.\n"
        . "- 요일만 말하고 '이번주/다음주' 없으면(예: '금요일') 오늘 이후 가장 가까운 그 요일(오늘 포함). 이번주 표에서 오늘보다 빠르면 다음주 표 사용.\n"
        . "- '오전/오후' 없는 시간은 한국어 일상 맥락으로 추론('3시'=15:00, '아침 8시'=08:00, '점심'=12:00, '저녁'=18:00).\n"
        . "- 종료시간 명시 없으면 end_time=null.\n"
        . "- find에서 '오늘/내일/이번주/다음주/이번달'이면 date_from~date_to 범위로 채움. 특정 키워드만 있으면 date_from/date_to는 null.\n"
        . "- ★find에서 특정 하루를 가리키면(예 '이번주 금요일 일정', '내일 일정', '7월 3일') date_from=date_to=그 날짜(기준표 사용). 제목 키워드 없으면 keyword는 빈 문자열로 두고 그날 전체를 보여줌.\n"
        . "- 반드시 JSON 한 개만 출력.";

    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 400,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $text]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false)            return ['ok' => false, 'msg' => 'AI 호출 실패: ' . $cerr];
    $j = json_decode($resp, true);
    if ($code !== 200 || !is_array($j)) {
        return ['ok' => false, 'msg' => 'AI 오류: ' . ($j['error']['message'] ?? ('HTTP ' . $code))];
    }
    $out = '';
    foreach (($j['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $out .= $b['text']; }

    // 코드블록/잡텍스트 방어 후 첫 JSON 객체 추출
    $out = trim($out);
    if (preg_match('/\{.*\}/s', $out, $m)) $out = $m[0];
    $p = json_decode($out, true);
    if (!is_array($p) || empty($p['intent'])) {
        return ['ok' => false, 'msg' => '음성 명령을 이해하지 못했습니다.'];
    }
    // 정규화
    $p['intent']    = in_array($p['intent'], ['create','find','delete'], true) ? $p['intent'] : 'unknown';
    $p['title']     = trim((string)($p['title'] ?? ''));
    $p['keyword']   = trim((string)($p['keyword'] ?? ''));
    $p['is_allday'] = !empty($p['is_allday']);
    foreach (['date','start_time','end_time','date_from','date_to'] as $k) {
        if (!isset($p[$k]) || $p[$k] === '' || $p[$k] === 'null') $p[$k] = null;
    }
    return ['ok' => true, 'parsed' => $p];
}

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

        // 기존 "당일만 수정" 로직 재사용
        $data             = $inst;
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
        // 신규가 앞쪽 → 기존을 뒤로
        return [date('Y-m-d H:i:s', $newEndTs), date('Y-m-d H:i:s', $newEndTs + $duration)];
    } else {
        // 신규가 뒤쪽 → 기존을 앞으로
        return [date('Y-m-d H:i:s', $newStartTs - $duration), date('Y-m-d H:i:s', $newStartTs)];
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

        // 그룹 추가 (빈 그룹 등록)
        case 'group_add':
            $d    = json_decode(file_get_contents('php://input'), true);
            $name = trim($d['name'] ?? '');
            if ($name === '') { echo json_encode(['ok' => false, 'msg' => '그룹명을 입력하세요.']); break; }
            echo json_encode(['ok' => true, 'added' => $contact->addGroup($name)]);
            break;

        // 그룹 이름변경 / 통합 (to 가 이미 있으면 통합)
        case 'group_rename':
            $d = json_decode(file_get_contents('php://input'), true);
            echo json_encode($contact->renameGroup($d['from'] ?? '', $d['to'] ?? ''));
            break;

        // 그룹 삭제 → 소속 연락처는 미분류로 이동
        case 'group_delete':
            $d = json_decode(file_get_contents('php://input'), true);
            echo json_encode($contact->deleteGroup($d['name'] ?? ''));
            break;

        // 구글 드라이브 명함 이미지 → Claude Vision 추출 → 주소록 적재
        case 'scan_cards':
            if (file_exists("./env/gdrive.inc"))    require_once "./env/gdrive.inc";
            if (file_exists("./env/anthropic.inc")) require_once "./env/anthropic.inc";
            @set_time_limit(300);
            @ignore_user_abort(true); // 배치 도중 클라이언트가 끊겨도 그 배치는 끝까지 처리·기록
            if (function_exists('session_write_close')) session_write_close(); // 긴작업 세션락 해제
            $d   = json_decode(file_get_contents('php://input'), true);
            $lim = (int)($d['limit'] ?? 15);
            $r2  = $contact->scanCardsFromDrive($lim);
            if (isset($r2['error'])) { echo json_encode(['ok'=>false,'msg'=>$r2['error']]); break; }
            echo json_encode(['ok' => true, 'data' => $r2]);
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
// 여행 모듈 (읽기전용 — 캘린더 기간 막대용. tbl_travel 단방향 조회)
// ==========================================================
function api_travel(string $action, PDO $pdo): void {
    // 정적지도 프록시 — DB 불필요, 바이너리(PNG) 응답이므로 먼저 처리하고 종료
    if ($action === 'staticmap') { travel_static_map(); return; }

    $travel = new Travel($pdo);
    $travel->ensureTable();

    switch ($action) {
        // 전체 여행 목록 (캘린더 막대는 start_dt/end_dt 로 클라이언트에서 필터)
        case 'list':
            echo json_encode(['ok' => true, 'data' => $travel->listTravels()]);
            break;

        // 수동 동기화 (로그인 세션으로 보호). id 있으면 그 여행 1건만 새로고침
        case 'sync':
            set_time_limit(0);            // 사진 많으면 길어질 수 있어 타임아웃 해제
            // ★중요: 세션 잠금 즉시 해제. sync 는 세션에 쓰지 않는데, 여기서 닫지 않으면
            //   이 긴 요청이 사용자 세션 파일을 독점 잠가, 같은 로그인의 다른 모든 페이지
            //   (심지어 로그인 POST)가 sync 가 끝날 때까지 대기 → 사이트 전체가 멈춘 것처럼 보인다.
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $tid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($tid > 0) {
                // 청크 새로고침: offset/limit 으로 N개씩 끊어 처리(대용량 여행 게이트웨이 타임아웃 방지).
                // 단건은 ignore_user_abort 를 켜지 않는다 → 탭을 닫으면 이 요청도 멈춰 워커를 안 잡음.
                $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
                $limit  = isset($_GET['limit'])  ? max(0, (int)$_GET['limit'])  : 0;
                $one = $travel->refreshTravel($tid, $offset, $limit);   // 단건 새로고침(청크)
                echo json_encode(['ok' => true, 'data' => $one ? [$one] : []]);
            } else {
                ignore_user_abort(true);               // 전체 신규폴더 풀스캔만 완주 보장
                $summary = $travel->sync();            // 전체: 신규 폴더만 풀스캔
                echo json_encode(['ok' => true, 'data' => $summary]);
            }
            break;

        // 사진 1장 메모 저장 (일기 보기)
        case 'photo_memo':
            $d   = json_decode(file_get_contents('php://input'), true);
            $pid = (int)($d['id'] ?? 0);
            if ($pid <= 0) { echo json_encode(['ok' => false, 'msg' => '사진 id가 없습니다.']); break; }
            $travel->setPhotoMemo($pid, $d['memo'] ?? '');
            echo json_encode(['ok' => true]);
            break;

        // 사진 1장 숨김/표시 (일기에서만 제외, 드라이브 원본 유지)
        case 'photo_hide':
            $d   = json_decode(file_get_contents('php://input'), true);
            $pid = (int)($d['id'] ?? 0);
            if ($pid <= 0) { echo json_encode(['ok' => false, 'msg' => '사진 id가 없습니다.']); break; }
            $travel->setPhotoHidden($pid, (bool)($d['hidden'] ?? true));
            echo json_encode(['ok' => true]);
            break;

        // 위치 미상 사진들에 좌표 직접 지정 (GPS 없는 사진 수동 등록 — EXIF 재편집 불가 우회)
        // spread(분)>0 이면 앵커 사진 촬영시각 전후 ±spread 분의 위치 미상 사진도 같은 좌표로 함께 지정.
        case 'photo_setloc':
            $d      = json_decode(file_get_contents('php://input'), true);
            $ids    = $d['ids'] ?? [];
            $lat    = (float)($d['lat'] ?? 0);
            $lng    = (float)($d['lng'] ?? 0);
            $addr   = trim((string)($d['addr'] ?? ''));
            $spread = max(0, min(240, (int)($d['spread'] ?? 0)));   // 전후 ±분 (0=끔, 최대 4시간)
            $tid    = (int)($d['travel_id'] ?? 0);
            if (!is_array($ids) || !$ids) { echo json_encode(['ok' => false, 'msg' => '사진이 선택되지 않았습니다.']); break; }
            if (abs($lat) < 0.0001 && abs($lng) < 0.0001) { echo json_encode(['ok' => false, 'msg' => '좌표가 올바르지 않습니다.']); break; }
            $explicit = count($ids);
            if ($spread > 0 && $tid > 0) {
                $near = $travel->findUnlocatedNear($tid, $ids, $spread);   // 전후 ±분 위치 미상 사진
                $ids  = array_values(array_unique(array_merge($ids, $near)));
            }
            $n = $travel->setPhotoLoc($ids, $lat, $lng, $addr !== '' ? $addr : null);
            echo json_encode(['ok' => true, 'count' => $n, 'auto' => max(0, $n - $explicit)]);
            break;

        // 한시적 공개 공유 링크 생성 (소유자 전용 — 파일 상단 require_login 으로 게이트됨)
        case 'share_create':
            $d   = json_decode(file_get_contents('php://input'), true);
            $tid = (int)($d['id'] ?? 0);
            $ttl = (int)($d['ttl'] ?? 86400);
            if ($tid <= 0) { echo json_encode(['ok' => false, 'msg' => '여행 id가 없습니다.']); break; }
            $sh = $travel->createShare($tid, $ttl);
            echo json_encode(['ok' => true, 'token' => $sh['token'], 'expires_at' => $sh['expires_at']]);
            break;

        // 현재 활성 공유 링크 조회 (소유자 화면 로드 시 기존 링크 표시)
        case 'share_status':
            $tid = (int)($_GET['id'] ?? 0);
            $sh  = $tid > 0 ? $travel->getActiveShare($tid) : null;
            echo json_encode(['ok' => true, 'token' => $sh['token'] ?? null, 'expires_at' => $sh['expires_at'] ?? null]);
            break;

        // 공유 즉시 중단 ('지금 닫기' — 활성 토큰 전부 무효화)
        case 'share_revoke':
            $d   = json_decode(file_get_contents('php://input'), true);
            $tid = (int)($d['id'] ?? 0);
            if ($tid <= 0) { echo json_encode(['ok' => false, 'msg' => '여행 id가 없습니다.']); break; }
            $travel->revokeShares($tid);
            echo json_encode(['ok' => true]);
            break;

        default:
            echo json_encode(['ok' => false, 'msg' => "unknown travel action: {$action}"]);
    }
}

// 네이버 정적지도 프록시: <img src=...staticmap&lat=&lng=> → 인증헤더 붙여 PNG 스트리밍
// (정적지도 API 는 인증헤더가 필요해 브라우저 <img> 가 직접 호출할 수 없음)
function travel_static_map(): void {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
        http_response_code(400); echo '{}'; return;
    }
    $r = GeoCoder::staticMap($lat, $lng);
    if (empty($r['ok'])) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'msg' => $r['msg'] ?? 'staticmap 실패']);
        return;
    }
    header('Content-Type: image/png');                // 상단 application/json 헤더 교체
    header('Cache-Control: public, max-age=2592000'); // 30일 브라우저 캐시 (좌표별 결정적)
    echo $r['body'];
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

// ==========================================================
// 습관 모듈
// ==========================================================
function api_habit(string $action, PDO $pdo): void {
    $hab = new Habit($pdo);

    switch ($action) {
        // 활성 습관 + 최근 로그(streak·히트맵용) + 오늘 완료여부
        case 'list':
            $today = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['today'] ?? '') ? $_GET['today'] : date('Y-m-d');
            // streak/히트맵 윈도: 기본 100일(이번 달 + 충분한 과거)
            $from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')
                     ? $_GET['from'] : date('Y-m-d', strtotime($today . ' -100 days'));
            echo json_encode(['ok' => true, 'data' => $hab->listActive($from, $today)], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            $d = json_decode(file_get_contents('php://input'), true);
            if (trim($d['title'] ?? '') === '') { echo json_encode(['ok' => false, 'msg' => '제목은 필수입니다.']); return; }
            $id = $hab->create($d);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        case 'update':
            $d = json_decode(file_get_contents('php://input'), true);
            if (!(int)($d['id'] ?? 0)) { echo json_encode(['ok' => false, 'msg' => 'id 없음']); return; }
            $hab->update($d);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $hab->delete($id);
            echo json_encode(['ok' => true]);
            break;

        // 지난(종료된) 습관 — 대시보드 회고
        case 'ended':
            echo json_encode(['ok' => true, 'data' => $hab->listEnded()], JSON_UNESCAPED_UNICODE);
            break;

        // 체크형 완료 토글 (날짜 단위)
        case 'toggle':
            $d    = json_decode(file_get_contents('php://input'), true);
            $hid  = (int)($d['habit_id'] ?? 0);
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'] ?? '') ? $d['date'] : date('Y-m-d');
            if (!$hid) { echo json_encode(['ok' => false, 'msg' => 'habit_id 없음']); return; }
            $r = $hab->toggle($hid, $date);
            echo json_encode(['ok' => true] + $r);
            break;

        // 측정형 수량 기록 upsert (amount<=0 = 취소)
        case 'log':
            $d    = json_decode(file_get_contents('php://input'), true);
            $hid  = (int)($d['habit_id'] ?? 0);
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'] ?? '') ? $d['date'] : date('Y-m-d');
            $amt  = (int)($d['amount'] ?? 0);
            if (!$hid) { echo json_encode(['ok' => false, 'msg' => 'habit_id 없음']); return; }
            $r = $hab->logAmount($hid, $date, $amt);
            echo json_encode(['ok' => true] + $r);
            break;

        // 측정형 증분 기록 (방금 한 양을 그날 총합에 더함)
        case 'add':
            $d     = json_decode(file_get_contents('php://input'), true);
            $hid   = (int)($d['habit_id'] ?? 0);
            $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'] ?? '') ? $d['date'] : date('Y-m-d');
            $delta = (int)($d['delta'] ?? 0);
            if (!$hid) { echo json_encode(['ok' => false, 'msg' => 'habit_id 없음']); return; }
            $r = $hab->addAmount($hid, $date, $delta);
            echo json_encode(['ok' => true] + $r);
            break;

        // 종료(졸업/그만둠) — 삭제 아님
        case 'end':
            $d = json_decode(file_get_contents('php://input'), true);
            $hid = (int)($d['habit_id'] ?? 0);
            $reason = ($d['reason'] ?? '') === 'completed' ? 'completed' : 'stopped';
            if (!$hid) { echo json_encode(['ok' => false, 'msg' => 'habit_id 없음']); return; }
            $hab->endHabit($hid, $reason, isset($d['at']) ? $d['at'] : null);
            echo json_encode(['ok' => true]);
            break;

        // 종료 해제(다시 진행)
        case 'reopen':
            $d = json_decode(file_get_contents('php://input'), true);
            $hid = (int)($d['habit_id'] ?? 0);
            if (!$hid) { echo json_encode(['ok' => false, 'msg' => 'habit_id 없음']); return; }
            $hab->reopen($hid);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}

// ==========================================================
// 목표 모듈
// ==========================================================
function api_goal(string $action, PDO $pdo): void {
    $goal = new Goal($pdo);

    switch ($action) {
        // 전체 목표 + 이번 기간 진행률
        case 'list':
            $today = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['today'] ?? '') ? $_GET['today'] : date('Y-m-d');
            echo json_encode(['ok' => true, 'data' => $goal->listWithProgress($today)], JSON_UNESCAPED_UNICODE);
            break;

        // 연결 분류 드롭다운용
        case 'categories':
            echo json_encode(['ok' => true, 'data' => $goal->categoryOptions()], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            $d = json_decode(file_get_contents('php://input'), true);
            if (trim($d['title'] ?? '') === '') { echo json_encode(['ok' => false, 'msg' => '제목은 필수입니다.']); return; }
            $id = $goal->create($d);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        case 'update':
            $d = json_decode(file_get_contents('php://input'), true);
            if (!(int)($d['id'] ?? 0)) { echo json_encode(['ok' => false, 'msg' => 'id 없음']); return; }
            $goal->update($d);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $goal->delete($id);
            echo json_encode(['ok' => true]);
            break;

        // MANUAL 모드 기간 달성 토글
        case 'toggle':
            $d     = json_decode(file_get_contents('php://input'), true);
            $gid   = (int)($d['goal_id'] ?? 0);
            $today = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['today'] ?? '') ? $d['today'] : date('Y-m-d');
            if (!$gid) { echo json_encode(['ok' => false, 'msg' => 'goal_id 없음']); return; }
            echo json_encode($goal->toggleManual($gid, $today));
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}
